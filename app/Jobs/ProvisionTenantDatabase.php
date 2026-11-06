<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProvisionTenantDatabase implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $tenantId) {}

    public function handle(TenantDatabaseManager $manager): void
    {
        $tenant = Tenant::findOrFail($this->tenantId);
        if ($tenant->status !== 'active') {
            return;
        }
        $manager->provision($tenant);
    }
}
