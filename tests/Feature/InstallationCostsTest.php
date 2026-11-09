<?php

namespace Tests\Feature;

use App\Modules\Installations\Domain\InstallationCostFactors;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

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
