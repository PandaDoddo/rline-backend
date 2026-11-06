<?php

namespace App\Tenancy;

use Illuminate\Auth\EloquentUserProvider;

class TenantUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        $query = parent::newModelQuery($model);
        $tenantId = app(TenantContext::class)->id();

        return $tenantId ? $query->where('is_active', true)->whereExists(fn ($q) => $q->selectRaw('1')->from('user_tenants')->whereColumn('user_tenants.user_id', 'users.id')->where('tenant_id', $tenantId)->where('user_tenants.is_active', true)) : $query->whereRaw('1 = 0');
    }
}
