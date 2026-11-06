<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantJobContext
{
    public function __construct(public int $tenantId) {}

    public function handle(object $job, callable $next): mixed
    {
        $tenant = Tenant::whereKey($this->tenantId)->where('status', 'active')->where('database_status', 'ready')->firstOrFail();
        $context = app(TenantContext::class);
        $context->set($tenant);
        try {
            return $next($job);
        } finally {
            $context->set(null);
        }
    }
}
