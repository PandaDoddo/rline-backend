<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

class TenantDatabaseIsolationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_tenant_databases_and_roles_are_physically_isolated(): void
    {
        $central = DB::connection('pgsql');
        $this->assertSame('rline_test', $central->getDatabaseName());
        $a = Tenant::create(['slug' => 'database-alpha', 'name' => 'Alpha', 'auth_config' => ['local' => ['enabled' => true]]]);
        $b = Tenant::create(['slug' => 'database-beta', 'name' => 'Beta', 'auth_config' => ['local' => ['enabled' => true]]]);
        $context = app(TenantContext::class);
        try {
            app(TenantDatabaseManager::class)->provision($a);
            app(TenantDatabaseManager::class)->provision($b);
            $a->refresh();
            $b->refresh();
            $this->assertSame('ready', $a->database_status);
            $this->assertSame('ready', $b->database_status);
            $this->assertNull($context->id());
            $context->set($a);
            DB::connection('tenant')->statement('CREATE TABLE isolation_probe (value text)');
            DB::connection('tenant')->table('isolation_probe')->insert(['value' => 'alpha-only']);
            $context->set($b);
            $this->assertNull(DB::connection('tenant')->selectOne("select to_regclass('public.isolation_probe') as name")->name);
            $this->assertSame($b->id, (int) DB::connection('tenant')->table('tenant_identity')->value('tenant_id'));
            config(['database.connections.wrong_tenant' => array_replace(config('database.connections.pgsql'), $a->database_config, ['database' => $b->database_config['database'], 'url' => null])]);
            try {
                DB::connection('wrong_tenant')->getPdo();
                $this->fail('Tenant A role connected to tenant B database.');
            } catch (\PDOException $exception) {
                $this->assertStringContainsString('permission denied', $exception->getMessage());
            }
            $context->set($a);
            $this->assertSame('alpha-only', DB::connection('tenant')->table('isolation_probe')->value('value'));
        } finally {
            $context->set(null);
            DB::purge('wrong_tenant');
            foreach ([$a, $b] as $tenant) {
                // Cleanup is restricted to this test cluster and generated tenant identifiers.
                $this->assertSame('rline_test', $central->getDatabaseName());
                $name = 'rline_t_'.$tenant->id;
                $central->unprepared('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
                $central->unprepared('DROP ROLE IF EXISTS "'.$name.'"');
            }
        }
    }
}
