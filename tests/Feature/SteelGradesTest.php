<?php

namespace Tests\Feature;

use App\Modules\Catalogs\Infrastructure\SteelGradeBaseline;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

class SteelGradesTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => $slug, 'auth_config' => ['local' => ['enabled' => true]]]);
        $this->tenants[] = $tenant;
        app(TenantDatabaseManager::class)->provision($tenant);

        return $tenant->refresh();
    }

    private function login(Tenant $tenant, string $role = 'owner'): User
    {
        $this->app['session']->flush();
        $user = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $role, 'auth_provider' => 'local']);
        $this->actingAs($user)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $tenant->slug]);

        return $user;
    }

    public function test_base_versions_overrides_reset_and_tenant_isolation(): void
    {
        $alpha = $this->tenant('steel-alpha');
        $beta = $this->tenant('steel-beta');
        $user = $this->login($alpha);
        $this->getJson('/api/steel-grades')->assertOk()->assertJsonPath('meta.total', 33);
        $id = $this->getJson('/api/steel-grades?q='.urlencode('ВСт3сп'))->assertOk()->json('data.0.id');
        $base = $this->getJson('/api/steel-grades/'.$id)->assertOk()->assertJsonPath('data.values.rm_nom', 425)->assertJsonPath('data.assessment.accepted_matcost', 1)->json('data');
        $this->assertNotEmpty($base['values']['stress']['rows']);
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => ['rm_nom' => '430,5'], 'verification' => ['rm_nom' => true]])->assertOk()->assertJsonPath('data.values.rm_nom', 430.5)->assertJsonPath('data.base_values.rm_nom', 425)->assertJsonPath('data.checks.rm_nom.by', $user->id);
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => ['rm_nom' => 500]])->assertConflict();
        app(TenantContext::class)->set($alpha);
        app(SteelGradeBaseline::class)->seed();
        app(TenantContext::class)->set(null);
        $this->getJson('/api/steel-grades/'.$id)->assertOk()->assertJsonPath('data.values.rm_nom', 430.5)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 2, 'reset_fields' => ['rm_nom']])->assertOk()->assertJsonMissingPath('data.overrides.rm_nom')->assertJsonMissingPath('data.checks.rm_nom');
        $this->getJson('/api/steel-grades/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 2);
        $this->login($beta);
        $this->getJson('/api/steel-grades/'.$id)->assertOk()->assertJsonPath('data.values.rm_nom', 425)->assertJsonPath('data.version', 1);
    }

    public function test_composition_and_stress_preserve_empty_zero_and_validate_ranges_sources_and_temperatures(): void
    {
        $tenant = $this->tenant('steel-values');
        $this->login($tenant);
        $values = ['name' => 'Тестовая марка', 'composition' => [['el' => 'C', 'min' => '0', 'max' => '0,2']], 'stress' => ['columns' => [['id' => 'gost', 'label' => 'Источник']], 'rows' => [['t' => '20', 'gost' => 0], ['t' => 50, 'gost' => null]]]];
        $data = $this->postJson('/api/steel-grades', ['values' => $values])->assertCreated()->assertJsonPath('data.values.composition.0.min', 0)->assertJsonPath('data.values.stress.rows.0.gost', 0)->assertJsonPath('data.values.stress.rows.1.gost', null)->json('data');
        $id = $data['id'];
        $this->postJson('/api/steel-grades', ['values' => ['name' => 'тестовая марка']])->assertUnprocessable()->assertJsonValidationErrors('values.name');
        foreach ([['composition' => [['el' => 'C', 'min' => 2, 'max' => 1]]], ['composition' => [['el' => 'Xx', 'min' => 0]]], ['rm_min' => 500, 'rm_max' => 200], ['rbi_matcost' => 1]] as $invalid) {
            $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => $invalid])->assertUnprocessable();
        }
        $stress = $values['stress'];
        $stress['rows'] = [['t' => 20, 'gost' => 100], ['t' => 20.0, 'gost' => 90]];
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => ['stress' => $stress]])->assertUnprocessable();
        $stress['rows'] = [['t' => 20, 'unknown' => 100]];
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => ['stress' => $stress]])->assertUnprocessable();
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => ['composition' => [['el' => 'Fe', 'min' => 90], ['el' => 'Cr', 'min' => 20]]]])->assertUnprocessable();
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'verification' => ['stress' => true], 'sources' => ['stress' => ['citation' => 'Протокол', 'conditions' => 'Тест']]])->assertOk()->assertJsonPath('data.sources.stress.citation', 'Протокол');
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 2, 'status' => 'archived'])->assertOk();
        $this->getJson('/api/steel-grades?status=archived')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 3, 'status' => 'active'])->assertOk();
        $this->login($tenant, 'member');
        $this->getJson('/api/steel-grades/'.$id)->assertOk();
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 4, 'values' => ['name' => 'Запрещено']])->assertForbidden();
        $other = $this->tenant('steel-other');
        $this->login($other);
        $this->getJson('/api/steel-grades/'.$id)->assertNotFound();
    }

    public function test_partial_stress_table_can_be_saved_cleared_but_not_verified_empty(): void
    {
        $this->login($this->tenant('steel-empty-table'));
        $id = $this->postJson('/api/steel-grades', ['values' => ['name' => 'Частичная таблица', 'stress' => ['columns' => [['id' => 'source', 'label' => 'Протокол']], 'rows' => []]]])->assertCreated()->json('data.id');
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'verification' => ['stress' => true]])->assertUnprocessable();
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 1, 'values' => ['stress' => null]])->assertOk()->assertJsonPath('data.values.stress', null);
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 2, 'values' => ['stress' => []]])->assertUnprocessable();
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
