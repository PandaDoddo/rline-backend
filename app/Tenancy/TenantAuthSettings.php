<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TenantAuthSettings
{
    private const FIELDS = [
        'local' => ['enabled'],
        'ldap' => ['enabled', 'uri', 'base_dn', 'bind_dn', 'bind_password', 'start_tls'],
        'ad' => ['enabled', 'uri', 'base_dn', 'bind_dn', 'bind_password', 'start_tls'],
        'multifactor' => ['enabled', 'issuer', 'discovery_url', 'client_id', 'client_secret'],
    ];

    public function present(Tenant $tenant): array
    {
        $result = [];
        foreach (self::FIELDS as $id => $fields) {
            $config = app(TenantContext::class)->provider($id, $tenant);
            $item = array_intersect_key($config, array_flip($fields));
            foreach (['bind_password', 'client_secret'] as $secret) {
                if (in_array($secret, $fields)) {
                    $item['has_'.$secret] = ! empty($item[$secret]);
                    unset($item[$secret]);
                }
            }
            $result[$id] = $item;
        }

        return $result;
    }

    public function update(Tenant $tenant, array $input): void
    {
        DB::transaction(fn () => $this->apply(Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail(), $input));
    }

    private function apply(Tenant $tenant, array $input): void
    {
        Validator::make(['providers' => $input], ['providers' => 'required|array:local,ldap,ad,multifactor'])->validate();
        $config = $tenant->auth_config ?? [];
        foreach ($input as $id => $values) {
            Validator::make(['settings' => $values], ['settings' => 'required|array:'.implode(',', self::FIELDS[$id])])->validate();
            $merged = array_replace(app(TenantContext::class)->provider($id, $tenant), $values);
            $rules = ['enabled' => 'required|boolean'];
            if ($id === 'ldap' || $id === 'ad') {
                $rules += ['start_tls' => 'required|boolean', 'uri' => 'nullable|url:ldap,ldaps|max:1024', 'base_dn' => 'nullable|string|max:1024', 'bind_dn' => 'nullable|string|max:1024', 'bind_password' => 'nullable|string|max:4096'];
                if ($merged['enabled']) {
                    foreach (['uri', 'base_dn', 'bind_dn', 'bind_password'] as $required) {
                        $rules[$required] = str_replace('nullable', 'required', $rules[$required]);
                    }
                    if (! str_starts_with($merged['uri'] ?? '', 'ldaps://') && ! $merged['start_tls']) {
                        throw ValidationException::withMessages(['providers.'.$id => 'LDAP требует LDAPS либо StartTLS.']);
                    }
                }
            } elseif ($id === 'multifactor') {
                $rules += ['issuer' => 'nullable|url:https|max:1024', 'discovery_url' => 'required|url:https|max:1024', 'client_id' => 'nullable|string|max:255', 'client_secret' => 'nullable|string|max:4096'];
                if ($merged['enabled']) {
                    foreach (['issuer', 'client_id', 'client_secret'] as $required) {
                        $rules[$required] = str_replace('nullable', 'required', $rules[$required]);
                    }
                }
            }
            Validator::make($merged, $rules)->validate();
            $config[$id] = array_intersect_key($merged, array_flip(self::FIELDS[$id]));
        }
        if (! collect($config)->contains(fn ($item, $id) => isset(self::FIELDS[$id]) && ($item['enabled'] ?? false))) {
            throw ValidationException::withMessages(['providers' => 'Нужен хотя бы один провайдер входа.']);
        }
        $enabled = array_keys(array_filter($config, fn ($item) => $item['enabled'] ?? false));
        if (! TenantMembership::where('tenant_id', $tenant->id)->where('is_active', true)->whereIn('role', ['owner', 'admin'])->whereIn('auth_provider', $enabled)->exists()) {
            throw ValidationException::withMessages(['providers' => 'Сначала предоставьте администратора с включённым способом входа.']);
        }
        $tenant->forceFill(['auth_config' => $config, 'auth_version' => $tenant->auth_version + 1])->save();
    }
}
