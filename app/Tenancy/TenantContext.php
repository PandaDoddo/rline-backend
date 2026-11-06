<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Illuminate\Support\Facades\DB;

class TenantContext
{
    private array $memberships = [];

    public ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->memberships = [];
        DB::purge('tenant');
        $this->tenant = $tenant;
        if ($tenant?->database_status === 'ready') {
            config(['database.connections.tenant' => array_replace(config('database.connections.pgsql'), $tenant->database_config, ['url' => null])]);
        } else {
            config(['database.connections.tenant' => null]);
        }
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function membership(int $userId): ?TenantMembership
    {
        if (! $this->id()) {
            return null;
        }
        if (! array_key_exists($userId, $this->memberships)) {
            $this->memberships[$userId] = TenantMembership::where('tenant_id', $this->id())->where('user_id', $userId)->where('is_active', true)->first();
        }

        return $this->memberships[$userId];
    }

    public function provider(string $id, ?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenant;
        $base = config('authentication.providers.'.$id, []);
        if (! $tenant) {
            return $base;
        }
        // Environment secrets belong only to the migrated bootstrap tenant.
        foreach (['uri', 'base_dn', 'bind_dn', 'bind_password', 'issuer', 'client_id', 'client_secret'] as $key) {
            if (array_key_exists($key, $base)) {
                $base[$key] = null;
            }
        }
        $base['enabled'] = false;

        return array_replace($base, ($tenant->auth_config ?? [])[$id] ?? []);
    }
}
