<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Installations\Models\Installation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InstallationRiskCriteriaTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private string $activeTenant;

    private function tenantDb(): Connection
    {
        app(TenantContext::class)->set(Tenant::where('slug', $this->activeTenant)->firstOrFail());

        return DB::connection('tenant');
    }

    private function login(string $slug, string $role = 'owner'): void
    {
        $this->activeTenant = $slug;
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

    private function installation(): string
    {
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }

        return $this->postJson('/api/installations', ['name' => 'Установка ремонтов', 'parent_id' => $parent])->assertCreated()->json('data.id');
    }

    public function test_defaults_are_explicit_and_scc_uses_current_mri_months_converted_to_years(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(12, 0));
        $this->login('risk-criteria');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/risk-criteria';
        $this->getJson('/api/rbi/risk-criteria/schema')->assertOk()->assertJsonPath('version', 'risk-targets-v1')->assertJsonCount(12, 'fields');
        $this->getJson($url)->assertOk()->assertJsonPath('data.version', 1)->assertJsonPath('data.effective.planScc', 4)->assertJsonPath('data.origins.planScc', 'policy')->assertJsonPath('data.effective.dThin', 5)->assertJsonPath('data.current_mri_months', null);
        $this->assertSame(0, $this->tenantDb()->table('installation_risk_targets')->count());
        $mri = fn ($date, $months) => ['id' => (string) Str::uuid(), 'kind' => 'mri', 'values' => ['effective_date' => $date, 'interval_months' => $months]];
        $this->patchJson('/api/installations/'.$id.'/maintenance', ['version' => 1, 'upserts' => [$mri('2026-01-01', 24), $mri('2027-01-01', 120)]])->assertOk();
        $this->getJson('/api/installations/'.$id.'/maintenance/summary')->assertOk()->assertJsonPath('data.current_mri_months', 24);
        $this->getJson($url)->assertOk()->assertJsonPath('data.effective.planScc', 2)->assertJsonPath('data.origins.planScc', 'mri')->assertJsonPath('data.current_mri_months', 24);
        $values = ['area' => null, 'pof' => 0, 'fin' => '1,25', 'H' => 12.5, 'planScc' => 12];
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 2, 'values' => $values])->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.overrides.area', null)->assertJsonPath('data.effective.pof', 0)->assertJsonPath('data.effective.fin', 1.25)->assertJsonPath('data.effective.planScc', 12)->assertJsonPath('data.origins.planScc', 'installation');
        $this->assertSame(5, $this->tenantDb()->table('installation_risk_target_overrides')->count());
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 3, 'reset_fields' => ['planScc']])->assertOk()->assertJsonPath('data.version', 4)->assertJsonPath('data.effective.planScc', 2)->assertJsonPath('data.origins.planScc', 'mri');
        $this->assertFalse(array_key_exists('planScc', $this->getJson($url)->json('data.overrides')));
        $this->getJson($url.'/events')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.changes.0.key', 'planScc');
        $db = $this->tenantDb();
        $this->assertSame('double precision', $db->selectOne("SELECT data_type FROM information_schema.columns WHERE table_name='installation_risk_targets' AND column_name='planScc'")->data_type);
        $this->assertNull($db->table('installation_risk_targets')->value('planScc'));
    }

    public function test_validation_conflicts_roles_archive_and_tenant_isolation_are_atomic(): void
    {
        $this->login('risk-roles');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/risk-criteria';
        foreach ([['H' => 12.2], ['H' => 0], ['area' => -1], ['dThin' => null], ['planRec' => 11], ['unknown' => 1]] as $values) {
            $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 1, 'values' => $values])->assertUnprocessable();
            $this->getJson($url)->assertJsonPath('data.version', 1);
            $this->assertSame(0, $this->tenantDb()->table('installation_risk_targets')->count());
        }
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 1, 'values' => ['area' => 50], 'reset_fields' => ['area']])->assertUnprocessable();
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 2, 'values' => ['pof' => 0]])->assertConflict();
        $this->login('risk-roles', 'member');
        $this->getJson($url)->assertOk();
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 1, 'values' => ['pof' => 0]])->assertForbidden();
        $this->login('risk-other');
        $this->getJson($url)->assertNotFound();
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 1, 'values' => ['pof' => 0]])->assertNotFound();
        $this->login('risk-roles');
        $this->tenantDb();
        Installation::findOrFail($id)->forceFill(['status' => 'archived'])->save();
        $this->getJson($url)->assertOk();
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 1, 'values' => ['pof' => 0]])->assertUnprocessable();
    }

    public function test_sql_guards_reject_hidden_values_invalid_horizons_and_disabled_required_targets(): void
    {
        $this->login('risk-sql');
        $id = $this->installation();
        $db = $this->tenantDb();
        foreach (['hidden', 'disabled', 'horizon', 'nan', 'unknown'] as $case) {
            try {
                $db->transaction(function () use ($db, $id, $case) {
                    $db->table('installation_risk_targets')->insert(['installation_id' => $id]);
                    if ($case === 'hidden') {
                        $db->table('installation_risk_targets')->update(['pof' => .1]);
                    } elseif ($case === 'disabled') {
                        $db->table('installation_risk_target_overrides')->insert(['installation_id' => $id, 'field_key' => 'dThin']);
                    } elseif ($case === 'horizon') {
                        $db->table('installation_risk_targets')->update(['H' => 12.2]);
                    } elseif ($case === 'nan') {
                        $db->statement("UPDATE installation_risk_targets SET pof='NaN'::float8");
                    } else {
                        $db->table('installation_risk_target_overrides')->insert(['installation_id' => $id, 'field_key' => 'unknown']);
                    }
                });
                $this->fail('Invalid typed risk criteria must be rejected by PostgreSQL.');
            } catch (\PDOException $exception) {
                $this->assertSame('23514', (string) $exception->getCode());
            }
            $this->assertSame(0, $db->table('installation_risk_targets')->count());
        }
    }

    protected function tearDown(): void
    {
        $this->travelBack();
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
