<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Installations\Application\InstallationCostInputValidation;
use App\Modules\Installations\Domain\InstallationCostFactors;
use App\Modules\Installations\Models\Installation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallationCostsTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function login(string $slug, string $role = 'owner'): void
    {
        $this->app['session']->flush();
        $tenant = Tenant::where('slug', $slug)->first();
        if (! $tenant) {
            $tenant = Tenant::create(['slug' => $slug, 'name' => $slug, 'auth_config' => ['local' => ['enabled' => true]]]);
            $this->tenants[] = $tenant;
            app(TenantDatabaseManager::class)->provision($tenant);
        }
        $user = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'auth_provider' => 'local']);
        $this->actingAs($user)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $slug]);
    }

    private function installation(): array
    {
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }

        return $this->postJson('/api/installations', ['name' => 'Установка затрат', 'parent_id' => $parent, 'passport' => ['area' => 100, 'stShift' => 0, 'stAll' => 30]])->assertCreated()->json('data');
    }

    public function test_sparse_values_sources_checks_derived_factors_and_shared_version(): void
    {
        $this->login('installation-costs');
        $installation = $this->installation();
        $url = '/api/installations/'.$installation['id'].'/costs';
        $this->getJson($url)->assertOk()->assertJsonPath('data.derived.fact_di', null)->assertJsonPath('data.derived.factmit', null)->assertJsonPath('data.derived.shift_density', 0)->assertJsonPath('data.derived.total_density', 0.3)->assertJsonPath('data.derived.effective_costfactor', 1);
        $this->patchJson($url, ['version' => 1, 'values' => ['envcost' => '0', 'prodcost' => '1 200,5', 'detsys' => '2', 'isosys' => '4', 'mitsys' => 'none', 'outagemult' => 0], 'verification' => ['envcost' => true], 'sources' => ['envcost' => ['citation' => 'Договор', 'conditions' => 'Нулевая стоимость']]])->assertOk()->assertJsonPath('data.values.envcost', 0)->assertJsonPath('data.values.prodcost', 1200.5)->assertJsonPath('data.derived.fact_di', 0.2)->assertJsonPath('data.derived.adapted_pair', true)->assertJsonPath('data.derived.ld_max', [40, 30, 20, 60])->assertJsonPath('data.derived.factmit', 0)->assertJsonPath('data.derived.effective_outagemult', 0);
        $this->getJson('/api/installations/'.$installation['id'])->assertJsonPath('data.version', 2);
        $this->patchJson($url, ['version' => 1, 'values' => ['envcost' => 500]])->assertConflict();
        $this->patchJson($url, ['version' => 2, 'values' => ['popdens' => '0,00009']])->assertOk()->assertJsonPath('data.values.envcost', 0)->assertJsonPath('data.values.popdens', 0.00009)->assertJsonPath('data.checks.envcost.by', $this->app['auth']->user()->id);
        $this->patchJson($url, ['version' => 3, 'values' => ['envcost' => null]])->assertOk()->assertJsonMissingPath('data.checks.envcost')->assertJsonPath('data.values.envcost', null);
        $this->patchJson($url, ['version' => 4, 'verification' => ['envcost' => true]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 4, 'values' => ['factdi' => 1]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 4, 'values' => ['detsys' => '6']])->assertUnprocessable();
        $this->patchJson($url, ['version' => 4, 'sources' => ['detsys' => ['citation' => 'Подмена таблицы']]])->assertUnprocessable();
        $this->getJson($url.'/events')->assertOk()->assertJsonPath('meta.total', 3);
        $this->login('installation-costs', 'member');
        $this->getJson($url)->assertOk();
        $this->patchJson($url, ['version' => 4, 'values' => ['envcost' => 1]])->assertForbidden();
        $this->login('installation-other');
        $this->getJson($url)->assertNotFound();
    }

    public function test_all_nine_detection_isolation_pairs_and_mitigation_match_source_tables(): void
    {
        $engine = app(InstallationCostFactors::class);
        $tables = $engine->tables();
        foreach ($tables['release'] as $pair) {
            $det = collect($tables['systems'])->first(fn ($row) => $row['sys'] === 'det' && $row['cls'] === $pair['d']);
            $iso = collect($tables['systems'])->first(fn ($row) => $row['sys'] === 'iso' && $row['cls'] === $pair['i']);
            $result = $engine->describe(['detsys' => $det['n'], 'isosys' => $iso['n']], []);
            $this->assertEquals((float) $pair['f'], $result['fact_di']);
            $this->assertCount(4, $result['ld_max']);
        }
        foreach ($tables['mitigation'] as $i => $row) {
            $this->assertEquals($row[4], $engine->describe(['mitsys' => (string) ($i + 1)], [])['factmit']);
        }
        $this->assertNull($engine->describe([], ['area' => 0, 'stShift' => 5])['shift_density']);
        $this->assertNull($engine->describe([], ['area' => 1e-320, 'stShift' => 10000000])['shift_density']);
    }

    public function test_cost_storage_matches_public_schema_and_rejects_conflicting_sql_states(): void
    {
        $fields = json_decode(file_get_contents(resource_path('schemas/installation-cost-properties-v1.json')), true, flags: JSON_THROW_ON_ERROR)['fields'];
        $this->assertSame(array_keys(config('installation-costs.fields')), array_keys($fields));
        $this->assertSame(1000.5, app(InstallationCostInputValidation::class)->validated(['version' => 1, 'values' => ['envcost' => '1 000,5']])['values']['envcost']);
        $tables = app(InstallationCostFactors::class)->tables();
        foreach (config('installation-costs.fields') as $key => $field) {
            $this->assertSame($field['type'], $fields[$key]['type']);
            if ($field['type'] === 'number') {
                $this->assertSame($field['max'], $fields[$key]['max']);
            } else {
                $options = $field['options'] === 'mit' ? ['none', ...array_map('strval', range(1, count($tables['mitigation'])))] : array_column(array_filter($tables['systems'], fn ($row) => $row['sys'] === $field['options']), 'n');
                $this->assertSame($options, $fields[$key]['options']);
            }
        }
        $this->login('cost-sql');
        $installation = $this->installation();
        $url = '/api/installations/'.$installation['id'].'/costs';
        $this->patchJson($url, ['version' => 1, 'values' => ['envcost' => 0, 'injcost' => null]])->assertOk();
        app(TenantContext::class)->set(Tenant::where('slug', 'cost-sql')->firstOrFail());
        $db = DB::connection('tenant');
        $this->assertFalse(Schema::connection('tenant')->hasColumn('installations', 'cost_values'));
        $invalid = [
            fn () => $db->table('installation_cost_parameters')->where('installation_id', $installation['id'])->update(['envcost' => -1]),
            fn () => $db->table('installation_cost_parameters')->where('installation_id', $installation['id'])->update(['detsys' => '5']),
            fn () => $db->table('installation_cost_annotations')->where('installation_id', $installation['id'])->where('field_key', 'envcost')->update(['value_present' => false]),
            fn () => $db->table('installation_cost_annotations')->where('installation_id', $installation['id'])->where('field_key', 'injcost')->update(['verified_by' => 1, 'verified_at' => now()]),
        ];
        foreach ($invalid as $write) {
            $db->beginTransaction();
            try {
                $write();
                $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->fail('Conflicting cost state must be rejected.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            } finally {
                $db->rollBack();
            }
        }
        $model = Installation::findOrFail($installation['id']);
        $this->assertEquals(0, $model->cost_values['envcost']);
        $this->assertArrayHasKey('injcost', $model->cost_values);
        $this->assertNull($model->cost_values['injcost']);
    }

    public function test_cost_migration_roundtrip_preserves_values_sources_checks_and_refuses_unknown_keys(): void
    {
        $this->login('cost-backfill');
        $installation = $this->installation();
        app(TenantContext::class)->set(Tenant::where('slug', 'cost-backfill')->firstOrFail());
        $db = DB::connection('tenant');
        $path = 'database/tenant-migrations/2026_11_10_000058_normalize_installation_costs.php';
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $db->table('migrations')->where('migration', '>=', '2026_11_10_000058')->count(), '--force' => true]));
        $db = DB::connection('tenant');
        $values = ['envcost' => 0, 'injcost' => null, 'popdens' => 0.00009, 'detsys' => '1', 'mitsys' => 'none'];
        $sources = ['envcost' => ['citation' => 'Протокол затрат', 'conditions' => null, 'by' => 1, 'at' => '2026-11-10T00:00:00+00:00']];
        $checks = ['envcost' => ['by' => 1, 'at' => '2026-11-10T00:00:00+00:00']];
        $db->table('installations')->where('id', $installation['id'])->update(['cost_values' => json_encode($values), 'cost_sources' => json_encode($sources), 'cost_checks' => json_encode($checks)]);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
        $model = Installation::findOrFail($installation['id']);
        $this->assertEquals($values, $model->cost_values);
        $this->assertEquals($sources, $model->cost_sources);
        $this->assertEquals($checks, $model->cost_checks);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $db->table('migrations')->where('migration', '>=', '2026_11_10_000058')->count(), '--force' => true]));
        $legacy = $db->table('installations')->where('id', $installation['id'])->first();
        $this->assertEquals($values, json_decode($legacy->cost_values, true));
        $this->assertEquals($sources, json_decode($legacy->cost_sources, true));
        $this->assertEquals($checks, json_decode($legacy->cost_checks, true));
        $db->table('installations')->where('id', $installation['id'])->update(['cost_values' => json_encode($values + ['unknown_key' => 1])]);
        try {
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]);
            $this->fail('Unknown cost must stop the migration.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unknown installation cost', $exception->getMessage());
        }
        $this->assertFalse(Schema::connection('tenant')->hasTable('installation_cost_parameters'));
        $this->assertArrayHasKey('unknown_key', json_decode($db->table('installations')->where('id', $installation['id'])->value('cost_values'), true));
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        $central = DB::connection('pgsql');
        $this->assertSame('rline_test', $central->getDatabaseName());
        foreach ($this->tenants as $tenant) {
            $name = 'rline_t_'.$tenant->id;
            $central->unprepared('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
            $central->unprepared('DROP ROLE IF EXISTS "'.$name.'"');
        }
        parent::tearDown();
    }
}
