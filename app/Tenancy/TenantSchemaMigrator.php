<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class TenantSchemaMigrator
{
    public function migrate(Tenant $tenant): void
    {
        $central = DB::connection('pgsql');
        $context = app(TenantContext::class);
        $previous = $context->tenant;
        $central->select('select pg_advisory_lock(?)', [910000000 + $tenant->id]);
        try {
            if ($tenant->database_status !== 'ready') {
                throw new \RuntimeException('Tenant database is not ready: '.$tenant->slug);
            }
            $context->set($tenant);
            $identity = DB::connection('tenant')->table('tenant_identity')->sole();
            if ((int) $identity->tenant_id !== $tenant->id) {
                throw new \RuntimeException('Tenant database identity mismatch.');
            }
            $exit = Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]);
            if ($exit !== 0) {
                throw new \RuntimeException('Tenant migration failed: '.$tenant->slug);
            }
        } finally {
            $context->set($previous);
            $central->select('select pg_advisory_unlock(?)', [910000000 + $tenant->id]);
        }
    }
}
