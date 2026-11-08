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
use Illuminate\Support\Str;

class EquipmentTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function setupTenant(string $slug): Tenant
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

    private function installation(): string
    {
        $enterprise = $this->postJson('/api/facility-nodes', ['kind' => 'enterprise', 'name' => 'Завод'])->assertCreated()->json('data.id');
        $site = $this->postJson('/api/facility-nodes', ['kind' => 'site', 'name' => 'Площадка', 'parent_id' => $enterprise])->assertCreated()->json('data.id');
        $production = $this->postJson('/api/facility-nodes', ['kind' => 'production', 'name' => 'Производство', 'parent_id' => $site])->assertCreated()->json('data.id');

        return $this->postJson('/api/installations', ['name' => 'КС-1', 'parent_id' => $production])->assertCreated()->json('data.id');
    }

    public function test_new_card_is_empty_except_entered_values_and_registry_reads_passport(): void
    {
        $this->login($this->setupTenant('equipment-alpha'));
        $installation = $this->installation();
        $body = ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сепаратор С-1', 'tag' => 'С-1', 'registration_number' => '123 РТН', 'commissioned_on' => '2009']];
        $created = $this->postJson('/api/equipment', $body)->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.passport.registration_number', '123')->assertJsonPath('data.passport.commissioned_on', '2009-01-01')
            ->assertJsonPath('data.passport.commissioned_precision', 'year')->assertJsonMissingPath('data.passport.pressure_operating_mpa');
        $id = $created->json('data.id');
        $this->getJson('/api/equipment?q='.urlencode('сепаратор'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.passport.name', 'Сепаратор С-1');
        $body['passport']['pressure_operating_mpa'] = '0,6';
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $body['passport'], 'version' => 1])->assertOk()->assertJsonPath('data.passport.pressure_operating_mpa', 0.6);
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/equipment?group=pipe')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/equipment/'.$id.'/events')->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/equipment', $body)->assertUnprocessable();
    }

    public function test_verification_tracks_actor_clears_on_change_and_conflict_preserves_data(): void
    {
        $user = $this->login($this->setupTenant('equipment-alpha'));
        $installation = $this->installation();
        $passport = ['name' => 'Сосуд', 'tag' => 'Е-1'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'verification' => ['tag' => true]])->assertOk()->assertJsonPath('data.checks.tag.by', $user->id);
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1])->assertConflict();
        $passport['tag'] = 'Е-2';
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2])->assertOk()->assertJsonMissingPath('data.checks.tag');
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 3, 'verification' => ['pressure_design_mpa' => true]])->assertUnprocessable();
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_archive_restore_and_validation(): void
    {
        $this->login($this->setupTenant('equipment-alpha'));
        $installation = $this->installation();
        $passport = ['name' => 'Трубопровод'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'pipe_tech', 'passport' => $passport])->assertCreated()->json('data.id');
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'status' => 'archived'])->assertOk();
        $this->getJson('/api/equipment')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/equipment?status=archived')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2, 'status' => 'active'])->assertOk()->assertJsonPath('data.id', $id);
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport + ['vessel_group' => '1'], 'version' => 3])->assertUnprocessable();
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 3, 'profile_code' => 'ves_fnp_rtn'])->assertUnprocessable();
        $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'pipe_tech', 'passport' => $passport + ['pressure_design_mpa' => -1]])->assertUnprocessable();
    }

    public function test_foreign_ids_and_parent_are_unavailable_and_member_cannot_write(): void
    {
        $alpha = $this->setupTenant('equipment-alpha');
        $beta = $this->setupTenant('equipment-beta');
        $this->login($alpha);
        $installation = $this->installation();
        $body = ['installation_id' => $installation, 'profile_code' => 'ppk', 'passport' => ['name' => 'ППК']];
        $id = $this->postJson('/api/equipment', $body)->assertCreated()->json('data.id');
        $this->login($beta);
        $this->getJson('/api/equipment/'.$id)->assertNotFound();
        $this->getJson('/api/equipment/'.$id.'/events')->assertNotFound();
        $this->postJson('/api/equipment', $body)->assertUnprocessable()->assertJsonValidationErrors('installation_id');
        $this->login($alpha, 'member');
        $this->getJson('/api/equipment/'.$id)->assertOk();
        $this->postJson('/api/equipment', $body)->assertForbidden();
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $body['passport'], 'version' => 1])->assertForbidden();
    }

    public function test_structure_is_atomic_preserves_codes_and_forbids_foreign_parents(): void
    {
        $this->login($this->setupTenant('equipment-structure'));
        $installation = $this->installation();
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сосуд']])->assertCreated()->json('data.id');
        $root = (string) Str::uuid();
        $element = (string) Str::uuid();
        $coil = (string) Str::uuid();
        $make = fn ($id, $kind, $parent = null) => ['id' => $id, 'kind' => $kind, 'parent_id' => $parent, 'name' => $kind, 'is_coil' => $kind === 'coil', 'status' => 'active', 'position' => 0];
        $body = ['installation_id' => $installation, 'passport' => ['name' => 'Сосуд'], 'version' => 1,
            'structure' => [$make($element, 'element', $root), $make($root, 'shell') + ['nominal_thickness_mm' => '12,5'], $make($coil, 'coil')]];
        $created = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.version', 2)->json('data.components');
        $codes = array_column($created, 'code', 'id');
        $this->assertSame('K-01', $codes[$root]);
        $this->assertSame('K-01.S1', $codes[$element]);
        $this->assertSame('K-02', $codes[$coil]);
        $this->assertEquals(12.5, collect($created)->firstWhere('id', $root)['nominal_thickness_mm']);
        $body['version'] = 2;
        $body['structure'] = [$make($root, 'shell') + ['material' => 'Сталь']];
        $body['structure'][0]['status'] = 'removed';
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonCount(3, 'data.components');
        $body['version'] = 3;
        $body['structure'][0]['status'] = 'active';
        $new = (string) Str::uuid();
        $body['structure'][] = $make($new, 'head');
        $result = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->json('data.components');
        $this->assertSame($codes[$root], collect($result)->firstWhere('id', $root)['code']);
        $this->assertSame('K-03', collect($result)->firstWhere('id', $new)['code']);
        $this->patchJson('/api/equipment/'.$id, $body)->assertConflict();
        $body['version'] = 4;
        $body['passport']['name'] = 'Не должно сохраниться';
        $body['structure'] = [$make((string) Str::uuid(), 'element', (string) Str::uuid())];
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.passport.name', 'Сосуд')->assertJsonPath('data.version', 4);
        $other = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Другой сосуд']])->assertCreated()->json('data.id');
        $body['version'] = 1;
        $body['structure'] = [$make($root, 'shell')];
        $this->patchJson('/api/equipment/'.$other, $body)->assertUnprocessable();
        $body['structure'] = [$make((string) Str::uuid(), 'element', $root)];
        $this->patchJson('/api/equipment/'.$other, $body)->assertUnprocessable();
    }

    public function test_primary_inspection_and_diagnostics_are_owned_versioned_and_preserve_empty_dates(): void
    {
        $this->login($this->setupTenant('equipment-diagnostics'));
        $installation = $this->installation();
        $input = ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сосуд', 'registration_authority' => 'РТН']];
        $created = $this->postJson('/api/equipment', $input)->assertCreated()->assertJsonCount(1, 'data.inspections')
            ->assertJsonPath('data.inspections.0.kind', 'ТО-2')->assertJsonPath('data.inspections.0.is_primary', true)->assertJsonPath('data.inspections.0.performed_on', null);
        $id = $created->json('data.id');
        $primary = $created->json('data.inspections.0');
        $next = ['id' => (string) Str::uuid(), 'kind' => 'ЭПБ', 'is_primary' => false, 'performed_on' => '2024-06-01', 'planned_on' => '2028-06-01', 'report_number' => 'ЭПБ-1'];
        $body = ['installation_id' => $installation, 'passport' => $input['passport'], 'version' => 1, 'inspections' => [$primary, $next]];
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.version', 2)->assertJsonCount(2, 'data.inspections');
        $body['version'] = 2;
        $body['passport']['registration_authority'] = 'ОТН';
        $body['inspections'] = [];
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.inspections.0.kind', 'ТО-1')->assertJsonPath('data.inspections.0.id', $primary['id'])->assertJsonCount(2, 'data.inspections');
        $body['version'] = 3;
        $body['inspections'] = [array_replace($primary, ['kind' => 'ТО-1', 'is_primary' => false])];
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['inspections'] = [$next];
        $body['inspections'][0]['performed_on'] = '2026-02-31';
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['inspections'] = [array_replace($next, ['id' => (string) Str::uuid(), 'is_primary' => true])];
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $other = $this->postJson('/api/equipment', $input)->assertCreated()->json('data.id');
        $body['version'] = 1;
        $body['inspections'] = [$next];
        $this->patchJson('/api/equipment/'.$other, $body)->assertUnprocessable();
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 3);
    }

    public function test_thickness_is_stable_explicit_and_verified_stages_are_locked(): void
    {
        $this->login($this->setupTenant('equipment-thickness'));
        $installation = $this->installation();
        $input = ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сосуд']];
        $created = $this->postJson('/api/equipment', $input)->assertCreated()->assertJsonCount(1, 'data.thickness.stages');
        $id = $created->json('data.id');
        $stage = $created->json('data.thickness.stages.0.id');
        $component = (string) Str::uuid();
        $cml = (string) Str::uuid();
        $body = ['installation_id' => $installation, 'passport' => $input['passport'], 'version' => 1,
            'structure' => [['id' => $component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Обечайка', 'is_coil' => false, 'status' => 'active', 'position' => 0]],
            'thickness' => ['cmls' => [['id' => $cml, 'component_id' => $component, 'point_count' => 3]], 'stages' => [['id' => $stage, 'measured_on' => '2026-01-01', 'verified' => true, 'measurements' => [['cml_id' => $cml, 'points' => ['12,5', null, 0]]]]]]];
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.thickness.cmls.0.code', 'CML-01.1')->assertJsonPath('data.thickness.stages.0.verified', true)->assertJsonPath('data.thickness.stages.0.measurements.0.points', [12.5, null, 0]);
        $body['version'] = 2;
        unset($body['structure']);
        $body['thickness']['stages'][0]['verified'] = false;
        $body['thickness']['stages'][0]['measurements'][0]['points'][1] = 0;
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['thickness']['stages'][0]['measurements'][0]['points'][1] = null;
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.thickness.stages.0.verified', false);
        $body['version'] = 3;
        $body['thickness']['stages'][0]['measurements'][0]['points'][0] = null;
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.thickness.stages.0.measurements.0.points', [null, null, 0]);
        $body['version'] = 4;
        $body['thickness']['cmls'][0]['point_count'] = 2;
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['thickness']['cmls'][0]['point_count'] = 3;
        $body['thickness']['cmls'][0]['component_id'] = (string) Str::uuid();
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        unset($body['thickness']);
        $body['inspections'] = [['id' => (string) Str::uuid(), 'kind' => 'ГИ', 'is_primary' => false, 'performed_on' => '2026-02-01']];
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonCount(1, 'data.thickness.stages');
        $body['version'] = 5;
        $body['inspections'] = [['id' => (string) Str::uuid(), 'kind' => 'ЭПБ', 'is_primary' => false, 'performed_on' => '2026-03-01']];
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonCount(2, 'data.thickness.stages');
        $body['version'] = 6;
        $body['structure'] = [['id' => $component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Обечайка', 'is_coil' => false, 'status' => 'removed', 'position' => 50]];
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.thickness.cmls.0.code', 'CML-01.1');
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonCount(1, 'data.thickness.cmls');
    }

    public function test_diagnostic_schedule_reads_facts_filters_plans_and_sorts_missing_last(): void
    {
        $alpha = $this->setupTenant('equipment-schedule');
        $beta = $this->setupTenant('equipment-schedule-other');
        $this->login($alpha);
        $installation = $this->installation();
        $ids = [];
        foreach (['Просрочен', 'Скоро', 'Без плана'] as $name) {
            $ids[] = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => $name]])->assertCreated()->json('data.id');
        }
        foreach ([$ids[0] => now()->subDay()->toDateString(), $ids[1] => now()->addDays(10)->toDateString()] as $id => $date) {
            $record = $this->getJson('/api/equipment/'.$id)->assertOk()->json('data');
            $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $record['passport'], 'version' => 1,
                'inspections' => [['id' => (string) Str::uuid(), 'kind' => 'ТО-1', 'is_primary' => false, 'planned_on' => $date]]])->assertOk();
        }
        $this->getJson('/api/diagnostic-schedule?plan_state=overdue')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('as_of', now()->toDateString())->assertJsonMissingPath('data.0.thickness');
        $this->getJson('/api/diagnostic-schedule?plan_state=upcoming&within_days=20')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $ids[1]);
        $this->getJson('/api/diagnostic-schedule?plan_state=missing')->assertOk()->assertJsonPath('data.0.id', $ids[2]);
        $this->getJson('/api/diagnostic-schedule?sort=next_on&direction=asc')->assertOk()->assertJsonPath('data.0.id', $ids[0])->assertJsonPath('data.2.id', $ids[2]);
        $this->getJson('/api/diagnostic-schedule?sort=next_on&direction=desc')->assertOk()->assertJsonPath('data.0.id', $ids[1])->assertJsonPath('data.2.id', $ids[2]);
        $this->getJson('/api/diagnostic-schedule?sort=unsafe')->assertUnprocessable();
        $this->login($beta);
        $this->getJson('/api/diagnostic-schedule')->assertOk()->assertJsonPath('meta.total', 0);
        $this->login($alpha, 'member');
        $this->getJson('/api/diagnostic-schedule?q='.urlencode('Скоро'))->assertOk()->assertJsonPath('meta.total', 1);
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
