<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Tenancy\StandAccess;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDomains;
use Closure;
use Illuminate\Http\Request;

class ResolveTenant
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->is('auth/*', 'api/*') || $request->is('api/health', 'api/public/tenants')) {
            return $next($request);
        }
        $header = $request->header('X-Tenant');
        $parameter = $request->input('tenant');
        abort_if($header && $parameter && $header !== $parameter, 422, 'Тенант в запросе не совпадает с заголовком.');
        $slug = $header ?: $parameter;
        if (config('domains.base')) {
            $hostSlug = app(TenantDomains::class)->slug($request->getHost());
            abort_unless($hostSlug, 404, 'Организация не найдена.');
            abort_if($slug && $slug !== $hostSlug, 422, 'Организация не совпадает с адресом кабинета.');
            $slug = $hostSlug;
        }
        if (! $slug && $request->hasSession() && $request->session()->has('tenant_id')) {
            $slug = Tenant::whereKey($request->session()->get('tenant_id'))->value('slug');
        }
        $slug ??= 'rline';
        abort_unless(is_string($slug) && preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $slug), 404, 'Организация не найдена.');
        app(StandAccess::class)->ensureAllowed($request, $slug);
        $tenant = Tenant::where('slug', $slug)->where('status', 'active')->first();
        abort_unless($tenant, 404, 'Организация недоступна.');
        if ($request->hasSession() && $request->session()->has('tenant_id')
            && (int) $request->session()->get('tenant_id') !== $tenant->id && ! $request->is('auth/tenant')) {
            abort(409, 'Сначала завершите сеанс текущей организации.');
        }
        $context = app(TenantContext::class);
        $context->set($tenant);
        try {
            if ($request->hasSession() && ! $request->is('auth/tenant') && $request->session()->has('tenant_version')
                && (int) $request->session()->get('tenant_version') !== $tenant->auth_version) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                abort(401, 'Настройки входа изменены. Войдите заново.');
            }
            // Bind pre-authentication state as well as authenticated sessions.
            if ($request->hasSession() && ! $request->is('auth/tenant')) {
                $request->session()->put(['tenant_id' => $tenant->id, 'tenant_version' => $tenant->auth_version]);
            }

            return $next($request);
        } finally {
            $context->set(null);
        }
    }
}
