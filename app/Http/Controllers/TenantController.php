<?php

namespace App\Http\Controllers;

use App\Auth\AuthAudit;
use App\Auth\AuthPluginManager;
use App\Http\Requests\UpdateTenantAuthenticationRequest;
use App\Jobs\ProvisionTenantDatabase;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Tenancy\StandAccess;
use App\Tenancy\TenantAuthSettings;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public function select(Request $request, TenantContext $context)
    {
        $target = $context->tenant;
        $oldTenant = Tenant::find($request->session()->get('tenant_id'));
        if ($oldTenant) {
            $context->set($oldTenant);
        }
        Auth::guard('web')->logout();
        $context->set($target);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(['tenant_id' => $context->id(), 'tenant_version' => $context->tenant->auth_version]);

        return ['tenant' => $context->tenant->only('id', 'slug', 'name'), 'providers' => app(AuthPluginManager::class)->listing()];
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);

        return app(StandAccess::class)->scope($request, Tenant::query())->orderBy('id')->paginate(30);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->is_platform_admin, 403);
        $data = $request->validate(['slug' => 'required|string|max:63|regex:/^[a-z0-9][a-z0-9-]*$/D|unique:tenants,slug', 'name' => 'required|string|max:255']);
        app(StandAccess::class)->ensureAllowed($request, $data['slug']);
        $tenant = DB::transaction(function () use ($data, $request) {
            $tenant = Tenant::create($data + ['auth_config' => ['local' => ['enabled' => true]]]);
            TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'role' => 'owner', 'auth_provider' => 'local']);
            AuthAudit::record('tenant_created', $request->user()->id, targetTenantId: $tenant->id);

            return $tenant;
        });

        ProvisionTenantDatabase::dispatch($tenant->id)->afterCommit();

        return response()->json($tenant->refresh(), 201);
    }

    public function update(Request $request, Tenant $tenant)
    {
        app(StandAccess::class)->ensureAllowed($request, $tenant->slug);
        abort_unless($request->user()->is_platform_admin, 403);
        $data = $request->validate(['name' => 'sometimes|required|string|max:255', 'status' => ['sometimes', 'string', Rule::in(['active', 'suspended'])]]);
        abort_if(($data['status'] ?? null) === 'suspended' && $tenant->id === app(TenantContext::class)->id(), 422, 'Нельзя приостановить текущий тенант.');
        $tenant = DB::transaction(function () use ($data, $tenant) {
            $locked = Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (isset($data['status']) && $data['status'] !== $locked->status) {
                $data['auth_version'] = $locked->auth_version + 1;
            }
            $locked->update($data);

            return $locked;
        });
        AuthAudit::record('tenant_updated', $request->user()->id, targetTenantId: $tenant->id);

        return $tenant;
    }

    /** Настройки провайдеров без значений секретов. */
    public function showAuthentication(Request $request, Tenant $tenant, TenantAuthSettings $settings)
    {
        app(StandAccess::class)->ensureAllowed($request, $tenant->slug);
        abort_unless($settings->canManage($request->user(), $tenant), 403);

        return ['tenant' => $tenant->only('id', 'slug', 'name', 'status', 'database_status'), 'providers' => $settings->present($tenant)];
    }

    /** Обновить провайдеры; отсутствующие поля и секреты сохраняются. Прежние сеансы завершаются. */
    public function updateAuthentication(UpdateTenantAuthenticationRequest $request, Tenant $tenant, TenantAuthSettings $settings)
    {
        app(StandAccess::class)->ensureAllowed($request, $tenant->slug);
        $settings->update($tenant, $request->validated('providers'));
        $tenant->refresh();
        AuthAudit::record('tenant_auth_updated', $request->user()->id, targetTenantId: $tenant->id);

        return ['tenant' => $tenant->only('id', 'slug', 'name', 'status', 'database_status'), 'providers' => $settings->present($tenant)];
    }

    public function database(TenantContext $context)
    {
        abort_unless($context->tenant->database_status === 'ready', 503, 'База организации ещё не подготовлена.');
        $identity = DB::connection('tenant')->table('tenant_identity')->sole();
        abort_unless((int) $identity->tenant_id === $context->id(), 503, 'Неверная база организации.');

        return ['tenant_id' => $context->id(), 'database' => 'connected'];
    }
}
