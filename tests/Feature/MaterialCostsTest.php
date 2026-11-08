<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

class MaterialCostsTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function login(string $slug, string $role = 'owner'): Tenant
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

        return $tenant->refresh();
    }

    public function test_shared_language_coefficient_reset_checks_history_and_isolation(): void
    {
        $this->login('cost-alpha');
        $rows = $this->getJson('/api/references/material-costs')->assertOk()->assertJsonCount(41, 'data')->json('data');
        $this->assertCount(1, array_filter($rows, fn ($row) => $row['adapted']));
        $row = collect($rows)->firstWhere('english_name', 'Carbon steel');
        $id = $row['id'];
        $grade = $this->getJson('/api/steel-grades?q='.urlencode('ВСт3сп'))->json('data.0.id');
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 1, 'values' => ['factor' => '1,75'], 'sources' => ['factor' => ['citation' => 'Тест', 'conditions' => 'Уточнение']], 'verification' => ['factor' => true]])->assertOk()->assertJsonPath('data.values.factor', 1.75)->assertJsonPath('data.base_values.factor', 1)->assertJsonPath('data.sources.factor.citation', 'Тест');
        $this->getJson('/api/steel-grades/'.$grade)->assertOk()->assertJsonPath('data.assessment.accepted_matcost', 1.75)->assertJsonPath('data.assessment.material_cost.reference_version', 2);
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 1, 'values' => ['factor' => 2]])->assertConflict();
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 2, 'sources' => ['factor' => ['citation' => 'Другой источник']]])->assertOk()->assertJsonMissingPath('data.checks.factor');
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 3, 'values' => ['factor' => null]])->assertOk()->assertJsonPath('data.values.factor', null);
        $this->getJson('/api/steel-grades/'.$grade)->assertOk()->assertJsonPath('data.assessment.accepted_matcost', null)->assertJsonPath('data.assessment.material_cost.warning', true);
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 4, 'verification' => ['factor' => true]])->assertUnprocessable();
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 4, 'reset_fields' => ['factor']])->assertOk()->assertJsonPath('data.values.factor', 1)->assertJsonMissingPath('data.overrides.factor');
        $this->getJson('/api/references/material-costs/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 4);
        $this->login('cost-beta');
        $this->getJson('/api/references/material-costs')->assertOk()->assertJsonPath('data.0.values.factor', 1)->assertJsonPath('data.0.version', 1);
        $this->login('cost-alpha', 'member');
        $this->patchJson('/api/references/material-costs/'.$id, ['version' => 5, 'values' => ['factor' => 2]])->assertForbidden();
        $this->getJson('/api/references/material-costs/'.$id.'/events')->assertOk();
    }

    public function test_invalid_factors_and_protected_originals_are_rejected(): void
    {
        $this->login('cost-rules');
        $id = $this->getJson('/api/references/material-costs')->json('data.0.id');
        foreach ([0, -1, 'NaN', 'Infinity', 1000001, false] as $factor) {
            $this->patchJson('/api/references/material-costs/'.$id, ['version' => 1, 'values' => ['factor' => $factor]])->assertUnprocessable();
        }
        foreach ([['status' => 'archived'], ['english_name' => 'x'], ['values' => ['unknown' => 1]], ['reset_fields' => ['english_name']]] as $invalid) {
            $this->patchJson('/api/references/material-costs/'.$id, ['version' => 1] + $invalid)->assertUnprocessable();
        }
        $this->getJson('/api/references/material-costs')->assertJsonPath('data.0.version', 1);
    }

    public function test_adopted_cost_is_shared_with_grade_but_writable_only_in_reference(): void
    {
        $this->login('cost-adopted');
        $grades = $this->getJson('/api/references/steel-costs')->assertOk()->json('data');
        $this->assertCount(33, $grades);
        $grade = collect($grades)->firstWhere('name', 'ВСт3сп');
        $id = $grade['id'];
        $this->assertEquals(1, $grade['cost']['factor']);
        $this->patchJson('/api/references/steel-costs/'.$id, ['version' => 1, 'values' => ['accepted_cost' => '2,2', 'cost_reason' => 'Проверенная оценка'], 'sources' => ['accepted_cost' => ['citation' => 'Договор']]])->assertOk()->assertJsonPath('data.cost.factor', 2.2)->assertJsonPath('data.cost.automatic_factor', 1)->assertJsonPath('data.cost.manual', true);
        $this->getJson('/api/steel-grades/'.$id)->assertOk()->assertJsonPath('data.assessment.accepted_matcost', 2.2);
        $this->patchJson('/api/steel-grades/'.$id, ['version' => 2, 'values' => ['accepted_cost' => 3]])->assertUnprocessable();
        $this->patchJson('/api/references/steel-costs/'.$id, ['version' => 1, 'values' => ['accepted_cost' => 3]])->assertConflict();
        $this->patchJson('/api/references/steel-costs/'.$id, ['version' => 2, 'values' => ['name' => 'wrong']])->assertUnprocessable();
        $this->patchJson('/api/references/steel-costs/'.$id, ['version' => 2, 'values' => ['accepted_cost' => null]])->assertOk()->assertJsonPath('data.cost.factor', null)->assertJsonPath('data.cost.manual', true);
        $this->patchJson('/api/references/steel-costs/'.$id, ['version' => 3, 'reset_fields' => ['accepted_cost', 'cost_reason']])->assertOk()->assertJsonPath('data.cost.factor', 1)->assertJsonPath('data.cost.manual', false);
        $this->getJson('/api/steel-grades/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 3);
        $this->login('cost-adopted', 'member');
        $this->patchJson('/api/references/steel-costs/'.$id, ['version' => 4, 'values' => ['accepted_cost' => 9]])->assertForbidden();
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
