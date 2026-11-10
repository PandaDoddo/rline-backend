<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Equipment\Application\EquipmentInputValidation;
use App\Modules\Equipment\Domain\ComponentConstructionSchema;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EquipmentTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    public function test_technical_position_reads_pipeline_lines_and_matches_registry_search_sort_schedule_and_navigation(): void
    {
        $tenant = $this->setupTenant('equipment-position');
        $this->login($tenant);
        $installation = $this->installation();
        $pipe = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'pipe_tech', 'passport' => ['name' => 'Линия масла', 'line_numbers' => '117/2Т, 117/3Т']])->assertCreated()->json('data');
        $this->assertSame('117/2Т, 117/3Т', $pipe['technical_position']);
        $this->assertNull($pipe['passport']['tag'] ?? null);
        $second = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'pipe_tech', 'passport' => ['name' => 'Вторая линия', 'line_numbers' => '20']])->assertCreated()->json('data.id');
        $vessel = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сосуд', 'tag' => '050']])->assertCreated()->json('data.id');
        $empty = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'pump', 'passport' => ['name' => 'Без позиции']])->assertCreated()->json('data.id');
        $this->getJson('/api/equipment?q='.urlencode('117/2Т'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.technical_position', '117/2Т, 117/3Т');
        $this->getJson('/api/equipment/navigation?q='.urlencode('117/2Т'))->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/api/diagnostic-schedule?q='.urlencode('117/2Т'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.technical_position', '117/2Т, 117/3Т');
        $this->assertSame([$vessel, $pipe['id'], $second, $empty], $this->getJson('/api/equipment?sort=tag')->assertOk()->json('data.*.id'));
        $this->assertSame([$second, $pipe['id'], $vessel, $empty], $this->getJson('/api/equipment?sort=tag&direction=desc')->assertOk()->json('data.*.id'));
        $this->patchJson('/api/equipment/'.$pipe['id'], ['installation_id' => $installation, 'version' => $pipe['version'], 'passport' => ['name' => 'Линия масла', 'line_numbers' => '220']])->assertOk()->assertJsonPath('data.technical_position', '220');
        $this->getJson('/api/equipment?q='.urlencode('117/2Т'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/diagnostic-schedule?q=220')->assertOk()->assertJsonPath('data.0.technical_position', '220');
        foreach (['Первый клапан', 'Второй клапан'] as $name) {
            $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ppk', 'passport' => ['name' => $name, 'protected_equipment_position' => '050']])
                ->assertCreated()->assertJsonPath('data.technical_position', '050')->assertJsonMissingPath('data.passport.tag');
        }
        $this->getJson('/api/equipment?q=050')->assertOk()->assertJsonPath('meta.total', 3);
        $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Чужой атрибут', 'protected_equipment_position' => '050']])->assertUnprocessable();
        $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ppk', 'passport' => ['name' => 'Повтор собственного тега', 'tag' => '050']])->assertUnprocessable();
        foreach (['7475', '7476'] as $number) {
            $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'pipe_tech', 'passport' => ['name' => 'Участок '.$number, 'tag' => 'Л-1', 'line_numbers' => $number]])
                ->assertCreated()->assertJsonPath('data.technical_position', 'Л-1')->assertJsonPath('data.passport.line_numbers', $number);
        }
        $this->getJson('/api/equipment?q='.urlencode('Л-1'))->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_registry_type_tree_accepts_static_dynamic_multi_and_explicit_empty_selections(): void
    {
        $tenant = $this->setupTenant('registry-type-families');
        $this->login($tenant);
        $installation = $this->installation();
        foreach (['ves_fnp_rtn', 'pipe_tech', 'pump', 'compr'] as $index => $profile) {
            $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => $profile, 'passport' => ['name' => 'Оборудование '.$index]])->assertCreated();
        }
        $this->getJson('/api/equipment?group=ves,pipe,tank,boiler,ppk,furnace')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/equipment?group=pump,compr,blower,turb,det,fan')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/equipment?group=ves,pump')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/equipment?group=none')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/equipment?group=lift')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/equipment?group=bad')->assertUnprocessable();
        $this->getJson('/api/equipment?group=ves,,pipe')->assertUnprocessable();
    }

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

    private function assertComponentProjectionMatches(array $expected, string $equipment): void
    {
        $actual = $this->getJson('/api/equipment/'.$equipment)->assertOk()->json('data.components');
        $this->assertEquals($expected, $actual, 'The SQL read projection must preserve the typed write DTO.');
    }

    public function test_registry_navigation_counts_all_pages_and_filters_each_facility_level_without_cross_tenant_data(): void
    {
        $tenant = $this->setupTenant('registry-navigation');
        $this->login($tenant);
        $first = $this->installation();
        $second = $this->installation();
        $ids = [];
        foreach ([[$first, 'ves_fnp_rtn', 'Сосуд первый'], [$first, 'pipe_tech', 'Трубопровод'], [$second, 'ves_fnp_rtn', 'Сосуд второй']] as [$installation, $profile, $name]) {
            $ids[] = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => $profile, 'passport' => ['name' => $name]])->assertCreated()->json('data.id');
        }
        $this->getJson('/api/equipment?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $navigation = $this->getJson('/api/equipment/navigation')->assertOk()->assertJsonPath('data.total', 3)->json('data');
        $this->assertSame(['pipe' => 1, 'ves' => 2], collect($navigation['groups'])->pluck('count', 'code')->sortKeys()->all());
        $locations = array_column($navigation['locations'], null, 'id');
        $this->assertSame(2, $locations[$first]['count']);
        $this->assertSame(1, $locations[$second]['count']);
        $this->getJson('/api/equipment?installation_id='.$first)->assertOk()->assertJsonPath('meta.total', 2);
        $parent = $locations[$first]['parent_id'];
        while ($parent !== null) {
            $this->assertSame(2, $locations[$parent]['count']);
            $this->getJson('/api/equipment?facility_node_id='.$parent)->assertOk()->assertJsonPath('meta.total', 2);
            $parent = $locations[$parent]['parent_id'];
        }
        $this->getJson('/api/equipment?facility_node_id='.Str::uuid())->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/equipment?facility_node_id=bad')->assertUnprocessable();
        $this->getJson('/api/equipment/navigation?q=Сосуд')->assertOk()->assertJsonPath('data.total', 2);
        $this->patchJson('/api/equipment/'.$ids[0], ['installation_id' => $first, 'passport' => ['name' => 'Сосуд первый'], 'version' => 1, 'status' => 'archived'])->assertOk();
        $this->getJson('/api/equipment/navigation?status=active')->assertOk()->assertJsonPath('data.total', 2);
        $this->getJson('/api/equipment/navigation?status=archived')->assertOk()->assertJsonPath('data.total', 1);
        $this->login($tenant, 'member');
        $this->getJson('/api/equipment/navigation')->assertOk()->assertJsonPath('data.total', 2);
        $other = $this->setupTenant('registry-navigation-other');
        $this->login($other, 'member');
        $this->getJson('/api/equipment/navigation')->assertOk()->assertJsonPath('data.total', 0)->assertJsonPath('data.locations', []);
    }

    public function test_dual_safety_valve_is_a_real_passport_input_and_requires_presence(): void
    {
        $tenant = $this->setupTenant('dual-safety-valve');
        $this->login($tenant);
        $installation = $this->installation();
        $input = ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сдвоенный ППК', 'relief_valve_dual' => 'да']];
        $this->postJson('/api/equipment', $input)->assertUnprocessable();
        $input['passport']['relief_valve_present'] = 'да';
        $id = $this->postJson('/api/equipment', $input)->assertCreated()->assertJsonPath('data.passport.relief_valve_dual', 'да')->json('data.id');
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.passport.relief_valve_dual', 'да');
        $body = ['installation_id' => $installation, 'passport' => $input['passport'], 'version' => 1];
        $body['passport']['relief_valve_present'] = 'нет';
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['passport']['relief_valve_dual'] = 'нет';
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.passport.relief_valve_dual', 'нет');
        app(TenantContext::class)->set($tenant);
        try {
            $this->assertSame('нет', DB::connection('tenant')->table('equipment_constructions')->where('equipment_id', $id)->value('relief_valve_dual'));
            try {
                DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_constructions')->where('equipment_id', $id)->update(['relief_valve_dual' => 'да']));
                $this->fail('A dual configuration cannot contradict absent safety valves.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_unknown_cml_plan_preserves_observed_points_and_can_be_clarified_without_losing_coordinates(): void
    {
        $tenant = $this->setupTenant('unknown-cml-plan');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Неизвестная схема контроля'];
        $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data');
        [$root, $cml] = array_map(fn () => (string) Str::uuid(), range(1, 2));
        $cmlInput = ['id' => $cml, 'component_id' => $root, 'code' => 'CML-01.7', 'point_count' => null, 'notes' => 'Плановое количество не установлено'];
        $stage = ['id' => $created['thickness']['stages'][0]['id'], 'measured_on' => '2026-11-09', 'verified' => false, 'measurements' => [['cml_id' => $cml, 'points' => [9, null, 0]]]];
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [['id' => $root, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0]], 'thickness' => ['cmls' => [$cmlInput], 'stages' => [$stage]]];
        $saved = $this->patchJson('/api/equipment/'.$created['id'], $body)->assertOk()->assertJsonPath('data.thickness.cmls.0.point_count', null)->assertJsonPath('data.thickness.stages.0.measurements.0.points', [9, null, 0])->json('data');
        $this->assertFalse($saved['condition']['elements'][0]['latest']['complete']);
        unset($body['structure']);
        $body['version'] = 2;
        $body['thickness'] = ['cmls' => [array_replace($cmlInput, ['point_count' => 2])]];
        $this->patchJson('/api/equipment/'.$created['id'], $body)->assertUnprocessable();
        $body['thickness']['cmls'][0]['point_count'] = 4;
        $this->patchJson('/api/equipment/'.$created['id'], $body)->assertOk()->assertJsonPath('data.thickness.cmls.0.point_count', 4)->assertJsonPath('data.thickness.stages.0.measurements.0.points', [9, null, 0]);
        $body['version'] = 3;
        $body['thickness']['cmls'][0]['point_count'] = 3;
        $this->patchJson('/api/equipment/'.$created['id'], $body)->assertUnprocessable();
        app(TenantContext::class)->set($tenant);
        try {
            $measurement = DB::connection('tenant')->table('equipment_thickness_values')->where('cml_id', $cml)->value('id');
            try {
                DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_thickness_points')->insert(['measurement_id' => $measurement, 'point_number' => 5, 'thickness_mm' => 12]));
                $this->fail('Observed coordinate cannot exceed a known plan.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_large_thickness_write_batches_values_and_preserves_null_zero_and_verified_locks(): void
    {
        $this->login($this->setupTenant('thickness-batch'));
        $installation = $this->installation();
        $passport = ['name' => 'Пакетная толщинометрия'];
        $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data');
        $root = (string) Str::uuid();
        $cmls = array_map(fn () => ['id' => (string) Str::uuid(), 'component_id' => $root, 'point_count' => 3], range(1, 25));
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [['id' => $root, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0]], 'thickness' => ['cmls' => $cmls, 'stages' => []]];
        $saved = $this->patchJson('/api/equipment/'.$created['id'], $body)->assertOk()->json('data');
        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^insert into "?equipment_thickness_(values|points)"?(?:\s|\()/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $stage = ['id' => $saved['thickness']['stages'][0]['id'], 'measured_on' => '2026-11-09', 'verified' => false, 'measurements' => array_map(fn ($cml) => ['cml_id' => $cml['id'], 'points' => [12.123456789, null, 0]], array_slice($cmls, 0, 24))];
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 2, 'thickness' => ['stages' => [$stage]]];
        $saved = $this->patchJson('/api/equipment/'.$created['id'], $body)->assertOk()->json('data.thickness');
        $this->assertCount(2, $writes, 'Measurement rows and point rows must be written in batches, not per CML.');
        $this->assertCount(24, $saved['stages'][0]['measurements']);
        foreach ($saved['stages'][0]['measurements'] as $measurement) {
            $this->assertSame([12.123456789, null, 0], $measurement['points']);
        }
        $body['version'] = 3;
        $body['thickness']['stages'][0]['verified'] = true;
        $body['thickness']['stages'][0]['measurements'] = [];
        $this->patchJson('/api/equipment/'.$created['id'], $body)->assertOk();
        $this->assertCount(2, $writes, 'Verifying an unchanged stage must not rewrite its measurement rows.');
        $body['version'] = 4;
        $body['thickness']['stages'][0]['measurements'] = [['cml_id' => $cmls[0]['id'], 'points' => [null, null, null]]];
        $this->patchJson('/api/equipment/'.$created['id'], $body)->assertUnprocessable();
        $body['thickness']['stages'][0]['measurements'] = [['cml_id' => $cmls[24]['id'], 'points' => [null, null, null]]];
        $this->patchJson('/api/equipment/'.$created['id'], $body)->assertUnprocessable();
    }

    public function test_cml_creation_preserves_source_gaps_and_never_changes_physical_identity(): void
    {
        $tenant = $this->setupTenant('cml-source-numbering');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Исходная нумерация CML'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        [$root, $child, $cml, $next] = array_map(fn () => (string) Str::uuid(), range(1, 4));
        $make = fn ($id, $parent, $code) => ['id' => $id, 'parent_id' => $parent, 'code' => $code, 'kind' => $parent ? 'element' : 'shell', 'name' => 'Компонент', 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$make($root, null, 'K-03'), $make($child, $root, 'K-03.S9')],
            'thickness' => ['cmls' => [['id' => $cml, 'component_id' => $child, 'code' => 'CML-03.9.7', 'point_count' => 2]], 'stages' => []]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.thickness.cmls.0.code', 'CML-03.9.7');
        unset($input['structure']);
        $input['version'] = 2;
        $input['thickness']['cmls'] = [['id' => $next, 'component_id' => $child, 'point_count' => 2]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.thickness.cmls');
        $this->assertSame('CML-03.9.8', collect($saved)->firstWhere('id', $next)['code']);
        $input['version'] = 3;
        foreach (['CML-03.9.9', 'CML-03.8.7'] as $code) {
            $input['thickness']['cmls'] = [['id' => $cml, 'component_id' => $child, 'code' => $code, 'point_count' => 2]];
            $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        }
        foreach (['CML-03.8.10', 'CML-03.9.07', 'CML-03.9.1000000'] as $code) {
            $input['thickness']['cmls'] = [['id' => (string) Str::uuid(), 'component_id' => $child, 'code' => $code, 'point_count' => 2]];
            $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        }
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 3);
        app(TenantContext::class)->set($tenant);
        try {
            try {
                DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_cmls')->where('id', $cml)->update(['code' => 'CML-03.9.9']));
                $this->fail('CML identity must remain immutable in PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_inline_assemblies_keep_physical_parts_and_cml_identity_and_reject_foreign_members(): void
    {
        $tenant = $this->setupTenant('inline-assembly');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Встроенный компенсатор'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        [$root, $before, $lens1, $lens2, $other, $assembly, $cml] = array_map(fn () => (string) Str::uuid(), range(1, 7));
        $make = fn ($id, $parent, $code) => ['id' => $id, 'parent_id' => $parent, 'code' => $code, 'kind' => $parent ? 'element' : 'shell', 'name' => $parent ? 'Элемент' : 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $group = ['id' => $assembly, 'code' => 'L1', 'kind' => 'lens_expansion_joint', 'after_component_id' => $before, 'nominal_pressure_mpa' => '1,6', 'joint_kind' => 'осевой', 'jacket_present' => false, 'members' => [$lens1, $lens2]];
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [
            $make($root, null, 'K-02') + ['assemblies' => [$group]], $make($before, $root, 'K-02.S1'),
            $make($lens1, $root, 'K-02.S101') + ['type_code' => 'lens'], $make($lens2, $root, 'K-02.S102') + ['type_code' => 'lens'], $make($other, null, 'K-03'),
        ], 'thickness' => ['cmls' => [['id' => $cml, 'component_id' => $lens1, 'point_count' => 1]], 'stages' => []]];
        $saved = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->json('data');
        $this->assertCount(5, $saved['components']);
        $this->assertSame('CML-02.101.1', $saved['thickness']['cmls'][0]['code']);
        $part = collect($saved['components'])->firstWhere('id', $root);
        $this->assertEquals(1.6, $part['assemblies'][0]['nominal_pressure_mpa']);
        $this->assertFalse($part['assemblies'][0]['jacket_present']);
        $this->assertSame([$lens1, $lens2], $part['assemblies'][0]['members']);
        $this->assertComponentProjectionMatches($saved['components'], $id);
        $body['version'] = 2;
        unset($body['thickness']);
        $body['structure'] = [$make($root, null, 'K-02') + ['assemblies' => [array_replace($group, ['members' => [$other]])]]];
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['structure'][0]['assemblies'][0] = array_replace($group, ['members' => [$lens1, $lens1]]);
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['structure'][0]['assemblies'][0] = array_replace($group, ['after_component_id' => $lens1]);
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['structure'][0]['assemblies'][0] = array_replace($group, ['status' => 'removed']);
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk();
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.thickness.cmls.0.id', $cml)->assertJsonPath('data.thickness.cmls.0.code', 'CML-02.101.1');
        app(TenantContext::class)->set($tenant);
        try {
            foreach ([fn () => DB::connection('tenant')->table('equipment_component_assemblies')->where('id', $assembly)->update(['code' => 'L2']),
                fn () => DB::connection('tenant')->table('equipment_component_assemblies')->where('id', $assembly)->update(['after_component_id' => $lens1]),
                fn () => DB::connection('tenant')->table('equipment_component_assembly_members')->insert(['assembly_id' => $assembly, 'equipment_id' => $id, 'member_component_id' => $other, 'position' => 2])] as $invalid) {
                try {
                    DB::connection('tenant')->transaction($invalid);
                    $this->fail('PostgreSQL must protect assembly identity, ownership and predecessor membership.');
                } catch (\PDOException $exception) {
                    $this->assertSame('23514', $exception->getCode());
                }
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
        // One atomic edit may remove the old member and make it the new predecessor.
        $body['version'] = 3;
        $body['structure'][0]['assemblies'][0] = array_replace($group, ['status' => 'active', 'after_component_id' => $lens1, 'members' => [$lens2]]);
        $updated = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->json('data');
        $assemblyData = collect($updated['components'])->firstWhere('id', $root)['assemblies'][0];
        $this->assertSame($lens1, $assemblyData['after_component_id']);
        $this->assertSame([$lens2], $assemblyData['members']);
    }

    public function test_unresolved_source_targets_remain_explicit_until_the_user_resolves_them(): void
    {
        $this->login($this->setupTenant('unresolved-source-targets'));
        $installation = $this->installation();
        $passport = ['name' => 'Исходные ссылки'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        [$a, $b, $group] = array_map(fn () => (string) Str::uuid(), range(1, 3));
        $make = fn ($id) => ['id' => $id, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Компонент', 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [
            $make($a) + ['connections' => [['id' => $group, 'joining_method' => 'сварное стыковое', 'targets' => [$b], 'unresolved_targets' => ['K-01#S1']]]], $make($b),
        ]];
        $saved = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->json('data.components');
        $connection = collect($saved)->firstWhere('id', $a)['connections'][0];
        $this->assertFalse($connection['complete']);
        $this->assertSame(['K-01#S1'], $connection['unresolved_targets']);
        $this->assertSame([$b], $connection['targets']);
        $this->assertComponentProjectionMatches($saved, $id);
        $body['version'] = 2;
        $body['structure'] = [$make($a) + ['connections' => [['id' => $group, 'notes' => 'Уточнить по схеме']]]];
        $saved = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->json('data.components');
        $this->assertSame(['K-01#S1'], collect($saved)->firstWhere('id', $a)['connections'][0]['unresolved_targets']);
        $body['version'] = 3;
        $body['structure'][0]['connections'][0]['unresolved_targets'] = ['K-01#S1', 'K-01#S1'];
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['structure'][0]['connections'][0]['unresolved_targets'] = [];
        $saved = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->json('data.components');
        $connection = collect($saved)->firstWhere('id', $a)['connections'][0];
        $this->assertTrue($connection['complete']);
        $this->assertSame([], $connection['unresolved_targets']);
        $this->assertSame([$b], $connection['targets']);
        $this->assertComponentProjectionMatches($saved, $id);
    }

    public function test_component_connections_preserve_groups_forward_targets_and_equipment_ownership(): void
    {
        $tenant = $this->setupTenant('component-graph');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Связи'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $c = (string) Str::uuid();
        $group = (string) Str::uuid();
        $reverse = (string) Str::uuid();
        $make = fn ($id) => ['id' => $id, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Компонент', 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [
            $make($a) + ['connections' => [['id' => $group, 'joining_method' => 'сварное стыковое', 'collar_present' => false, 'targets' => [$b, $c]]]], $make($b), $make($c),
        ]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $connection = collect($saved)->firstWhere('id', $a)['connections'][0];
        $this->assertSame([$b, $c], $connection['targets']);
        $this->assertTrue($connection['complete']);
        $this->assertFalse($connection['collar_present']);
        $this->assertComponentProjectionMatches($saved, $id);
        $input['version'] = 2;
        $input['structure'] = [$make($c) + ['connections' => [['id' => $reverse, 'joining_method' => 'резьбовое', 'thread_kind' => 'дюймовая', 'thread_size' => 'G1/2', 'targets' => [$a]]]]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk();
        $input['version'] = 3;
        $input['structure'] = [$make($a) + ['connections' => [['id' => $group, 'targets' => [$b]]]]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $connection = collect($saved)->firstWhere('id', $a)['connections'][0];
        $this->assertSame($group, $connection['id']);
        $this->assertSame('сварное стыковое', $connection['joining_method']);
        $this->assertSame([$b], $connection['targets']);
        $input['version'] = 4;
        $input['structure'][0]['connections'][0]['targets'] = [$a];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['connections'][0]['targets'] = [$b, $b];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['connections'][0] = ['id' => $reverse, 'targets' => [$b]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $other = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Другая ЕО']])->assertCreated()->json('data.id');
        $foreign = (string) Str::uuid();
        $this->patchJson('/api/equipment/'.$other, ['installation_id' => $installation, 'passport' => ['name' => 'Другая ЕО'], 'version' => 1, 'structure' => [$make($foreign)]])->assertOk();
        $input['structure'][0]['connections'][0] = ['id' => $group, 'targets' => [$foreign]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['connections'][0] = ['id' => $group, 'complete' => true];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $blank = (string) Str::uuid();
        $input['structure'][0]['connections'][0] = ['id' => $blank, 'targets' => []];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertCount(1, collect($saved)->firstWhere('id', $a)['connections']);
        $input['version'] = 5;
        $input['structure'][0]['connections'][0] = ['id' => $group, 'status' => 'removed'];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame('removed', collect($saved)->firstWhere('id', $a)['connections'][0]['status']);
        $this->assertSame([$b], collect($saved)->firstWhere('id', $a)['connections'][0]['targets']);
        $incomplete = (string) Str::uuid();
        $input['version'] = 6;
        $input['structure'][0]['connections'][0] = ['id' => $incomplete, 'joining_method' => 'фланцевое', 'targets' => []];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertFalse(collect(collect($saved)->firstWhere('id', $a)['connections'])->firstWhere('id', $incomplete)['complete']);
        app(TenantContext::class)->set($tenant->refresh());
        try {
            $db = DB::connection('tenant');
            try {
                $db->table('equipment_component_connection_targets')->insert(['connection_id' => $group, 'equipment_id' => $id, 'target_component_id' => $foreign, 'position' => 0]);
                $this->fail('Foreign targets must fail the composite FK.');
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->errorInfo[0]);
            }
            try {
                $db->table('equipment_component_connections')->where('id', $group)->update(['component_id' => $c]);
                $this->fail('Connection ownership must be immutable.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->errorInfo[0]);
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_component_history_is_append_only_paged_and_separates_event_date_from_recording(): void
    {
        $tenant = $this->setupTenant('component-history');
        $actor = $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'История'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $component = (string) Str::uuid();
        $entries = [];
        for ($index = 0; $index < 31; $index++) {
            $entries[] = ['id' => (string) Str::uuid(), 'kind' => 'legacy', 'occurred_on' => $index ? sprintf('2000-01-%02d', $index) : null, 'actor_name' => null, 'description' => 'Событие '.$index, 'source_reference' => 'old:history/'.$index];
        }
        $row = ['id' => $component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Компонент', 'is_coil' => false, 'status' => 'active', 'position' => 0, 'history' => $entries];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$row]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.history_count', 31)->json('data.components');
        $this->assertArrayNotHasKey('history', $saved[0]);
        $this->assertComponentProjectionMatches($saved, $id);
        $historyUrl = '/api/equipment/'.$id.'/components/'.$component.'/history';
        $this->getJson($historyUrl)->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 31)->assertJsonPath('data.0.occurred_on', '2000-01-30');
        $unknown = $this->getJson($historyUrl.'?page=2')->assertOk()->assertJsonCount(1, 'data')->json('data.0');
        $this->assertNull($unknown['occurred_on']);
        $this->assertNull($unknown['actor_name']);
        $this->assertSame($actor->id, $unknown['recorded_by']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $unknown['recorded_at']);
        $input['version'] = 2;
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.history_count', 31);
        $input['version'] = 3;
        $input['structure'][0]['history'][0]['description'] = 'Попытка перезаписи';
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['history'] = [$entries[0] + ['recorded_by' => 9999]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $this->getJson('/api/equipment/'.$id.'/components/'.Str::uuid().'/history')->assertNotFound();
        $this->login($tenant, 'member');
        $this->getJson($historyUrl)->assertOk();
        $this->patchJson('/api/equipment/'.$id, $input)->assertForbidden();
        app(TenantContext::class)->set($tenant->refresh());
        try {
            foreach (['update', 'delete'] as $operation) {
                try {
                    $query = DB::connection('tenant')->table('equipment_component_history')->where('id', $entries[0]['id']);
                    $operation === 'delete' ? $query->delete() : $query->update(['description' => 'Подмена']);
                    $this->fail('Historical facts must be immutable.');
                } catch (QueryException $exception) {
                    $this->assertSame('23514', $exception->errorInfo[0]);
                }
            }
            $this->assertSame(31, DB::connection('tenant')->table('equipment_component_history')->count());
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_conical_courses_and_flange_construction_preserve_their_independent_fields(): void
    {
        $tenant = $this->setupTenant('conical-courses');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Конический пояс'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $root = (string) Str::uuid();
        $course = (string) Str::uuid();
        $flange = (string) Str::uuid();
        $make = fn ($id, $type, $parent = null) => ['id' => $id, 'parent_id' => $parent, 'kind' => $parent ? 'element' : 'shell', 'type_code' => $type, 'name' => $type, 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [
            $make($root, 'shell'),
            $make($course, 'shell_course', $root) + ['diameter_mm' => 1220, 'nominal_thickness_mm' => 12, 'shell_geometry' => ['shell_form' => 'конический', 'small_diameter_mm' => '800,12345678', 'small_thickness_mm' => 10]],
            $make($flange, 'flange', $course) + ['construction' => ['flange_type' => 'юбочный', 'execution' => 'B (выступ)', 'nominal_pressure_mpa' => 1.6]],
        ]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame('B (выступ)', collect($saved)->firstWhere('id', $flange)['construction']['execution']);
        $this->assertSame('юбочный', collect($saved)->firstWhere('id', $flange)['construction']['flange_type']);
        $this->assertEquals(800.12345678, collect($saved)->firstWhere('id', $course)['shell_geometry']['small_diameter_mm']);
        $this->assertComponentProjectionMatches($saved, $id);
        $input['version'] = 2;
        $input['structure'] = [$make($flange, 'flange', $course) + ['shell_geometry' => ['shell_form' => 'конический']]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [$make($course, 'shell_course', $root) + ['shell_geometry' => ['shell_form' => 'спиральный']]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['shell_geometry'] = null;
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertNull(collect($saved)->firstWhere('id', $course)['shell_geometry']);
        $this->assertEquals(1220, collect($saved)->firstWhere('id', $course)['diameter_mm']);
    }

    public function test_tube_coil_and_expansion_geometry_preserve_explicit_counts_shapes_and_absence(): void
    {
        $tenant = $this->setupTenant('thermal-geometries');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Теплообменные элементы'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_teplo', 'passport' => $passport])->assertCreated()->json('data.id');
        $bundle = (string) Str::uuid();
        $coil = (string) Str::uuid();
        $joint = (string) Str::uuid();
        $tube = (string) Str::uuid();
        $body = (string) Str::uuid();
        $lens = (string) Str::uuid();
        $make = fn ($id, $kind, $type, $parent = null) => ['id' => $id, 'parent_id' => $parent, 'kind' => $kind, 'type_code' => $type, 'name' => $type, 'is_coil' => $kind === 'coil', 'status' => 'active', 'position' => 0];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [
            $make($bundle, 'tube_bundle', 'tube_bundle'),
            $make($tube, 'element', 'heat_exchange_tube', $bundle) + ['length_mm' => '1500', 'tube_geometry' => ['tube_form' => 'прямые', 'tube_count' => '19']],
            $make($coil, 'coil', 'internal_coil'),
            $make($body, 'element', 'coil_body', $coil) + ['coil_role' => 'body', 'coil_geometry' => ['coil_form' => 'спиральный']],
            $make($joint, 'other', 'lens_expansion_joint') + ['construction' => ['nominal_pressure_mpa' => 1.6], 'expansion_joint' => ['joint_kind' => 'осевой', 'jacket_present' => false]],
            $make($lens, 'element', 'lens', $joint) + ['lens_geometry' => ['outer_diameter_mm' => '329,12345678']],
        ]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame(19, collect($saved)->firstWhere('id', $tube)['tube_geometry']['tube_count']);
        $this->assertFalse(collect($saved)->firstWhere('id', $joint)['expansion_joint']['jacket_present']);
        $this->assertEquals(329.12345678, collect($saved)->firstWhere('id', $lens)['lens_geometry']['outer_diameter_mm']);
        $this->assertNull(collect($saved)->firstWhere('id', $bundle)['tube_geometry']);
        $this->assertComponentProjectionMatches($saved, $id);
        $input['version'] = 2;
        $input['structure'] = [$make($tube, 'element', 'heat_exchange_tube', $bundle) + ['tube_geometry' => ['tube_count' => '19,5']]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['tube_geometry']['tube_count'] = 0;
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [$make($body, 'element', 'coil_body', $coil) + ['coil_geometry' => ['coil_form' => 'прямые']]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [$make($tube, 'element', 'heat_exchange_tube', $bundle) + ['tube_geometry' => ['tube_form' => 'U-образные']]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame(19, collect($saved)->firstWhere('id', $tube)['tube_geometry']['tube_count']);
        $input['version'] = 3;
        $input['structure'] = [$make($lens, 'element', 'lens', $joint) + ['lens_geometry' => null]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertNull(collect($saved)->firstWhere('id', $lens)['lens_geometry']);
    }

    public function test_connection_boss_and_valve_details_are_typed_and_do_not_duplicate_common_facts(): void
    {
        $tenant = $this->setupTenant('component-connections');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Присоединения'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $make = fn ($type, $kind = 'other') => ['id' => (string) Str::uuid(), 'parent_id' => null, 'kind' => $kind, 'type_code' => $type, 'name' => $type, 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $boss = $make('boss') + ['material' => '09Г2С', 'construction' => ['execution' => '1'], 'boss_geometry' => ['design_type' => '1', 'height_mm' => '100,12345678'], 'end_connection' => ['thread_size' => 'M18×2,0', 'instrument_connection' => false]];
        $nozzle = $make('nozzle', 'nozzle') + ['end_connection' => ['far_end_kind' => 'наружная резьба', 'thread_kind' => 'метрическая', 'thread_size' => 'М48х2', 'instrument_connection' => true, 'instrument_kind' => 'уровнемер']];
        $valve = $make('valve') + ['construction' => ['nominal_pressure_mpa' => '1,6'], 'valve_details' => ['valve_class' => 'запорная', 'valve_kind' => 'кран шаровой', 'drive_kind' => 'ручной']];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$boss, $nozzle, $valve]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $first = collect($saved)->firstWhere('id', $boss['id']);
        $this->assertSame('09Г2С', $first['material']);
        $this->assertSame('1', $first['construction']['execution']);
        $this->assertEquals(100.12345678, $first['boss_geometry']['height_mm']);
        $this->assertFalse($first['end_connection']['instrument_connection']);
        $this->assertSame('М48х2', collect($saved)->firstWhere('id', $nozzle['id'])['end_connection']['thread_size']);
        $this->assertSame('ручной', collect($saved)->firstWhere('id', $valve['id'])['valve_details']['drive_kind']);
        $this->assertComponentProjectionMatches($saved, $id);
        $input['version'] = 2;
        $input['structure'] = [$make('shell', 'shell') + ['end_connection' => ['thread_size' => 'M20']]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [$nozzle];
        $input['structure'][0]['boss_geometry'] = ['height_mm' => 100];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        unset($input['structure'][0]['boss_geometry']);
        $input['structure'][0]['end_connection'] = ['instrument_kind' => null];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk();
        $input['version'] = 3;
        $input['structure'][0]['end_connection'] = null;
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertNull(collect($saved)->firstWhere('id', $nozzle['id'])['end_connection']);
    }

    public function test_coil_ports_roles_and_body_structure_are_independent_and_keep_stable_identity(): void
    {
        $tenant = $this->setupTenant('coil-assembly');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Два змеевика'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $child = (string) Str::uuid();
        $make = fn ($id, $parent = null) => ['id' => $id, 'parent_id' => $parent, 'kind' => $parent ? 'element' : 'coil', 'name' => $parent ? 'Тело' : 'Змеевик', 'is_coil' => $parent === null, 'status' => 'active', 'position' => 0];
        $coilA = $make($a) + ['type_code' => 'internal_coil', 'construction' => ['coil_body_kind' => 'mono'], 'ports' => [['direction' => 'inlet', 'mark' => 'А1', 'service_name' => 'Вход', 'connection_kind' => 'через бобышку'], ['direction' => 'outlet', 'mark' => 'А2']]];
        $coilB = $make($b) + ['type_code' => 'external_coil', 'ports' => [['direction' => 'inlet', 'mark' => 'Б1']]];
        $element = $make($child, $a) + ['coil_role' => 'body', 'type_code' => 'coil_body'];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$element, $coilA, $coilB]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $first = collect($saved)->firstWhere('id', $a);
        $portId = $first['ports'][0]['id'];
        $this->assertSame('А1', $first['ports'][0]['mark']);
        $this->assertSame('mono', $first['construction']['coil_body_kind']);
        $this->assertSame('body', collect($saved)->firstWhere('id', $child)['coil_role']);
        $this->assertSame('Б1', collect($saved)->firstWhere('id', $b)['ports'][0]['mark']);
        $this->assertComponentProjectionMatches($saved, $id);
        $input['version'] = 2;
        $input['structure'] = [$make($a) + ['ports' => [['direction' => 'inlet', 'mark' => 'А1 новое']]]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $first = collect($saved)->firstWhere('id', $a);
        $this->assertSame($portId, $first['ports'][0]['id']);
        $this->assertSame('через бобышку', $first['ports'][0]['connection_kind']);
        $this->assertSame('А2', $first['ports'][1]['mark']);
        $this->assertCount(3, $saved);
        $input['version'] = 3;
        $input['structure'][0]['ports'][] = ['direction' => 'inlet', 'mark' => 'Дубль'];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [$make($a) + ['ports' => [['direction' => 'inlet', 'mark' => null, 'service_name' => null, 'connection_kind' => null]]]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk();
        $input['version'] = 4;
        $input['structure'][0]['ports'] = [['direction' => 'inlet', 'mark' => 'Восстановлен']];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame($portId, collect($saved)->firstWhere('id', $a)['ports'][0]['id']);
        $shell = array_replace($make((string) Str::uuid()), ['kind' => 'shell', 'is_coil' => false, 'ports' => [['direction' => 'inlet', 'mark' => 'Недопустимо']]]);
        $input['version'] = 5;
        $input['structure'] = [array_replace($element, ['coil_role' => 'inlet_boss'])];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame('inlet_boss', collect($saved)->firstWhere('id', $child)['coil_role']);
        $input['version'] = 6;
        $input['structure'] = [array_replace($element, ['coil_role' => 'outlet_boss'])];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components');
        $this->assertSame('outlet_boss', collect($saved)->firstWhere('id', $child)['coil_role']);
        $input['version'] = 7;
        $input['structure'] = [$shell];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        unset($input['structure'][0]['ports']);
        $input['structure'][0]['construction'] = ['coil_body_kind' => 'multi'];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        unset($input['structure'][0]['construction']);
        $input['structure'][0]['coil_role'] = 'inlet';
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        app(TenantContext::class)->set($tenant->refresh());
        try {
            DB::connection('tenant')->table('equipment_component_ports')->where('id', $portId)->update(['direction' => 'outlet']);
            $this->fail('Port identity must be immutable in PostgreSQL.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_component_details_are_batch_loaded_without_queries_per_component(): void
    {
        $tenant = $this->setupTenant('component-batch-loading');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Пакетное чтение'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $structure = [];
        for ($index = 0; $index < 9; $index++) {
            $structure[] = ['id' => (string) Str::uuid(), 'parent_id' => null, 'kind' => 'head', 'type_code' => 'head', 'name' => 'Днище '.$index, 'is_coil' => false, 'status' => 'active', 'position' => $index, 'construction' => ['mark' => 'Д-'.$index], 'head_geometry' => ['height_mm' => 150 + $index]];
        }
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => $structure])->assertOk();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            foreach (['equipment_component_cards', 'equipment_component_constructions', 'equipment_component_head_geometries', 'equipment_component_fitting_geometries', 'equipment_component_end_connections', 'equipment_component_boss_geometries', 'equipment_component_valve_details', 'equipment_component_tube_geometries', 'equipment_component_coil_geometries', 'equipment_component_lens_geometries', 'equipment_component_expansion_joints', 'equipment_component_shell_geometries', 'equipment_component_ports'] as $table) {
                if (str_contains($query->sql, 'from "'.$table.'"')) {
                    $queries[$table] = ($queries[$table] ?? 0) + 1;
                }
            }
        });
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonCount(9, 'data.components')->assertJsonPath('data.components.8.head_geometry.height_mm', 158);
        $this->assertSame(['equipment_component_cards' => 1], $queries, 'The former 12 per-table queries must be replaced by one batch projection.');
        foreach ($queries as $table => $count) {
            $this->assertSame(1, $count, $table.' must be loaded once for the entire card.');
        }
    }

    public function test_special_geometry_is_typed_and_incompatible_type_changes_need_explicit_clearing(): void
    {
        $tenant = $this->setupTenant('component-geometry');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Геометрия'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $root = (string) Str::uuid();
        $item = ['id' => $root, 'parent_id' => null, 'kind' => 'other', 'type_code' => 'elbow', 'name' => 'Отвод', 'is_coil' => false, 'status' => 'active', 'position' => 0, 'fitting_geometry' => ['bend_radius_designation' => '1,5DN (тип 3D)', 'bend_angle_deg' => '90,12345678']];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$item]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.fitting_geometry.bend_radius_designation', '1,5DN (тип 3D)')->assertJsonPath('data.components.0.fitting_geometry.bend_angle_deg', 90.12345678);
        $input['version'] = 2;
        $input['structure'][0]['type_code'] = 'reducer';
        unset($input['structure'][0]['fitting_geometry']);
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['fitting_geometry'] = ['large_diameter_mm' => 159];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['fitting_geometry'] = null;
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.fitting_geometry', null);
        $input['version'] = 3;
        $input['structure'][0]['fitting_geometry'] = ['large_diameter_mm' => '159,12345678', 'small_diameter_mm' => 57];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.fitting_geometry.large_diameter_mm', 159.12345678);
        $input['version'] = 4;
        $input['structure'][0]['head_geometry'] = ['height_mm' => 150];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        unset($input['structure'][0]['head_geometry']);
        $head = array_replace($item, ['id' => (string) Str::uuid(), 'kind' => 'head', 'type_code' => 'head', 'name' => 'Днище', 'fitting_geometry' => null, 'head_geometry' => ['shape' => 'эллиптическое', 'flanging' => 'отбортовано', 'height_mm' => '315,12345678']]);
        $input['structure'] = [$head];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk();
        $input['version'] = 5;
        $input['structure'][0]['head_geometry']['height_mm'] = 0;
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['head_geometry'] = null;
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk();
        app(TenantContext::class)->set($tenant->refresh());
        try {
            $this->assertSame(0, DB::connection('tenant')->table('equipment_component_head_geometries')->count());
            $this->assertSame(1, DB::connection('tenant')->table('equipment_component_fitting_geometries')->count());
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_component_type_catalog_covers_original_vocabulary_and_guards_placement(): void
    {
        $tenant = $this->setupTenant('component-types');
        $this->login($tenant);
        $types = $this->getJson('/api/equipment/catalog')->assertOk()->json('component_types');
        $reference = json_decode(file_get_contents(base_path('tests/Fixtures/component-type-reference.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($reference['root_types'] as $name) {
            $this->assertNotNull(collect($types)->first(fn ($row) => $row['name'] === $name && $row['root_kind'] !== null), $name);
        }
        foreach ($reference['element_types'] as $name) {
            $this->assertNotNull(collect($types)->first(fn ($row) => $row['name'] === $name && $row['element_allowed']), $name);
        }
        $installation = $this->installation();
        $passport = ['name' => 'Типы компонентов'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $root = (string) Str::uuid();
        $child = (string) Str::uuid();
        $item = ['id' => $root, 'parent_id' => null, 'kind' => 'shell', 'type_code' => 'shell', 'name' => 'Обечайка', 'is_coil' => false, 'status' => 'active', 'position' => 0];
        $element = array_replace($item, ['id' => $child, 'parent_id' => $root, 'kind' => 'element', 'type_code' => 'shell_course', 'name' => 'Пояс']);
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$element, $item]];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk();
        $input['version'] = 2;
        $input['structure'] = [array_replace($element, ['type_code' => 'internal_coil'])];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [array_replace($item, ['type_code' => 'flange'])];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [array_replace($item, ['type_code' => 'unknown'])];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'] = [array_replace($item, ['type_code' => null])];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.type_code', null);
        app(TenantContext::class)->set($tenant->refresh());
        try {
            DB::connection('tenant')->table('equipment_components')->where('id', $child)->update(['type_code' => 'internal_coil']);
            $this->fail('Database must enforce type placement.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_component_construction_is_typed_partial_and_does_not_create_empty_rows(): void
    {
        $tenant = $this->setupTenant('component-construction');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Конструкция'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $component = (string) Str::uuid();
        $item = ['id' => $component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Обечайка', 'is_coil' => false, 'status' => 'active', 'position' => 0, 'construction' => ['is_primary' => false, 'nominal_pressure_mpa' => '0', 'mark' => 'А1', 'manufacturing_standard' => 'ТУ 102-488-95', 'assembly_kind' => 'courses']];
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$item]];
        $saved = $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->json('data.components.0.construction');
        $this->assertFalse($saved['is_primary']);
        $this->assertEquals(0, $saved['nominal_pressure_mpa']);
        $this->assertNull($saved['execution']);
        $input['version'] = 2;
        $input['structure'][0]['construction'] = ['nominal_pressure_mpa' => '1,6'];
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.construction.mark', 'А1')->assertJsonPath('data.components.0.construction.nominal_pressure_mpa', 1.6);
        $input['version'] = 3;
        $input['structure'][0]['construction'] = ['assembly_kind' => 'invented'];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        $input['structure'][0]['construction'] = ['foreign_json' => true];
        $this->patchJson('/api/equipment/'.$id, $input)->assertUnprocessable();
        unset($input['structure'][0]['construction']);
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.construction.mark', 'А1');
        $input['version'] = 4;
        $input['structure'][0]['construction'] = array_fill_keys(array_keys(ComponentConstructionSchema::LABELS), null);
        $this->patchJson('/api/equipment/'.$id, $input)->assertOk()->assertJsonPath('data.components.0.construction', null);
        app(TenantContext::class)->set($tenant->refresh());
        try {
            $this->assertSame(0, DB::connection('tenant')->table('equipment_component_constructions')->count());
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_manual_thickness_campaigns_preserve_diagnostics_and_verified_measurements(): void
    {
        $tenant = $this->setupTenant('manual-thickness');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Внеплановое УЗТ', 'commissioned_on' => '2000-01-01'];
        $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data');
        $url = '/api/equipment/'.$created['id'];
        $component = (string) Str::uuid();
        $cml = (string) Str::uuid();
        $stage = (string) Str::uuid();
        $input = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1,
            'structure' => [['id' => $component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0, 'nominal_thickness_mm' => 12]],
            'thickness' => ['cmls' => [['id' => $cml, 'component_id' => $component, 'point_count' => 3]], 'stages' => [['id' => $stage, 'origin' => 'manual', 'measured_on' => '2020-03-04', 'verified' => true, 'measurements' => [['cml_id' => $cml, 'points' => [10.123456, null, 0]]]]]]];
        $saved = $this->patchJson($url, $input)->assertOk()->json('data');
        $this->assertSame($created['inspections'], $saved['inspections']);
        $manual = collect($saved['thickness']['stages'])->firstWhere('id', $stage);
        $this->assertNull($manual['inspection_id']);
        $this->assertSame('manual', $manual['origin']);
        $this->assertEquals([10.123456, null, 0], $manual['measurements'][0]['points']);
        unset($input['structure']);
        $input['version'] = 2;
        $input['thickness']['cmls'] = [];
        $input['thickness']['stages'][0]['measured_on'] = '2020-03-05';
        $this->patchJson($url, $input)->assertUnprocessable();
        $input['thickness']['stages'][0]['verified'] = false;
        $this->patchJson($url, $input)->assertUnprocessable();
        $input['thickness']['stages'][0]['measured_on'] = '2020-03-04';
        $this->patchJson($url, $input)->assertOk();
        $input['version'] = 3;
        $input['thickness']['stages'][0]['measured_on'] = '2021-03-04';
        $input['thickness']['stages'][0]['measurements'][0]['points'] = [9, null, 0];
        $saved = $this->patchJson($url, $input)->assertOk()->json('data');
        $this->assertSame(1, count($saved['inspections']));
        $this->assertNotEmpty($saved['condition']['elements'][0]['series']);
        $input['version'] = 4;
        $input['thickness']['stages'][0]['origin'] = 'inspection';
        $this->patchJson($url, $input)->assertUnprocessable();
        $input['thickness']['stages'][0]['origin'] = 'manual';
        $input['thickness']['stages'][0]['measured_on'] = null;
        $this->patchJson($url, $input)->assertUnprocessable();
        $input['thickness']['stages'][0]['id'] = (string) Str::uuid();
        unset($input['thickness']['stages'][0]['origin']);
        $input['thickness']['stages'][0]['measured_on'] = '2022-01-01';
        $this->patchJson($url, $input)->assertUnprocessable();
        app(TenantContext::class)->set($tenant->refresh());
        $this->assertSame(2, DB::connection('tenant')->table('equipment_thickness_stages')->count());
        try {
            DB::connection('tenant')->table('equipment_thickness_stages')->where('id', $stage)->update(['inspection_id' => $created['inspections'][0]['id']]);
            $this->fail('Stage origin must be immutable in the database.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_original_component_numbers_with_gaps_preserve_cml_codes_and_advance_counters(): void
    {
        $tenant = $this->setupTenant('component-source-codes');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Сосуд с исходными номерами'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $url = '/api/equipment/'.$id;
        $root = (string) Str::uuid();
        $child = (string) Str::uuid();
        $cml = (string) Str::uuid();
        $section = (string) Str::uuid();
        $sectionChild = (string) Str::uuid();
        $sectionCml = (string) Str::uuid();
        $make = fn ($id, $parent, $code = null) => array_filter(['id' => $id, 'parent_id' => $parent, 'code' => $code, 'kind' => $parent ? 'element' : 'shell', 'name' => $parent ? 'Пояс' : 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0], fn ($value, $key) => $key !== 'code' || $value !== null, ARRAY_FILTER_USE_BOTH);
        $data = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$make($child, $root, 'K-03.S9'), $make($root, null, 'K-03'), $make($sectionChild, $section, 'K-S1.S2'), $make($section, null, 'K-S1')], 'thickness' => ['cmls' => [['id' => $cml, 'component_id' => $child, 'point_count' => 1], ['id' => $sectionCml, 'component_id' => $sectionChild, 'point_count' => 1]], 'stages' => []]];
        $saved = $this->patchJson($url, $data)->assertOk()->assertJsonPath('data.thickness.cmls.0.code', 'CML-03.9.1')->json('data');
        $this->assertSame('K-03', collect($saved['components'])->firstWhere('id', $root)['code']);
        $this->assertSame('CML-S1.2.1', collect($saved['thickness']['cmls'])->firstWhere('id', $sectionCml)['code']);
        $root2 = (string) Str::uuid();
        $child2 = (string) Str::uuid();
        $data['version'] = 2;
        unset($data['thickness']);
        $data['structure'] = [$make($child2, $root), $make($root2, null)];
        $saved = $this->patchJson($url, $data)->assertOk()->json('data.components');
        $this->assertSame('K-04', collect($saved)->firstWhere('id', $root2)['code']);
        $this->assertSame('K-03.S10', collect($saved)->firstWhere('id', $child2)['code']);
        $data['version'] = 3;
        $data['structure'] = [$make($root, null, 'K-05')];
        $this->patchJson($url, $data)->assertUnprocessable();
        $data['structure'] = [$make((string) Str::uuid(), $root, 'K-99.S1')];
        $this->patchJson($url, $data)->assertUnprocessable();
        $data['structure'] = [$make((string) Str::uuid(), null, 'K-03')];
        $this->patchJson($url, $data)->assertUnprocessable();
        $next = (string) Str::uuid();
        $data['structure'] = [$make($next, null)];
        $saved = $this->patchJson($url, $data)->assertOk()->json('data.components');
        $this->assertSame('K-05', collect($saved)->firstWhere('id', $next)['code']);
        app(TenantContext::class)->set($tenant->refresh());
        try {
            try {
                DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_components')->where('id', $root)->update(['code' => 'K-99']));
                $this->fail('Code mutation must be rejected by PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
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

    public function test_component_physical_inputs_preserve_precision_and_accept_decimal_comma(): void
    {
        $tenant = $this->setupTenant('component-precision');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Точная геометрия'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $component = (string) Str::uuid();
        $url = '/api/equipment/'.$id;
        $row = ['id' => $component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Обечайка', 'is_coil' => false, 'status' => 'active', 'position' => 0, 'diameter_mm' => '1 234,123456', 'length_mm' => '0,00001', 'nominal_thickness_mm' => '12,12345678', 'rejection_passport_mm' => '0,00001'];
        $this->patchJson($url, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => [$row]])->assertOk()->assertJsonPath('data.components.0.diameter_mm', 1234.123456)->assertJsonPath('data.components.0.length_mm', 0.00001)->assertJsonPath('data.components.0.nominal_thickness_mm', 12.12345678)->assertJsonPath('data.components.0.rejection_passport_mm', 0.00001);
        $this->getJson($url)->assertJsonPath('data.components.0.nominal_thickness_mm', 12.12345678);
        app(TenantContext::class)->set($tenant->refresh());
        try {
            $db = DB::connection('tenant');
            $this->assertEquals(0.00001, $db->table('equipment_components')->where('id', $component)->value('length_mm'));
            $steps = $db->table('migrations')->where('migration', '>=', '2026_11_09_000028')->count();
            try {
                Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--step' => $steps, '--force' => true]);
                $this->fail('Lossy rollback must be refused.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Rollback would round component input values', $exception->getMessage());
            }
            $this->assertEquals(12.12345678, $db->table('equipment_components')->where('id', $component)->value('nominal_thickness_mm'));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/tenant-migrations', '--force' => true]));
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_tube_and_each_coil_have_independent_typed_parameters_states_checks_and_stable_identity(): void
    {
        $tenant = $this->setupTenant('operating-scopes');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Теплообменник', 'pressure_operating_mpa' => 2];
        $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_teplo', 'passport' => $passport])->assertCreated()->assertJsonPath('data.operating_scopes.0.persisted', false)->json('data');
        $url = '/api/equipment/'.$created['id'];
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $structure = [['id' => $a, 'parent_id' => null, 'kind' => 'coil', 'name' => 'Змеевик А', 'is_coil' => true, 'status' => 'active', 'position' => 0], ['id' => $b, 'parent_id' => null, 'kind' => 'coil', 'name' => 'Змеевик Б', 'is_coil' => true, 'status' => 'active', 'position' => 1]];
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => $structure, 'operating_scopes' => [
            ['kind' => 'tube', 'component_id' => null, 'values' => ['pressure_operating_mpa' => 0, 'temperature_min_c' => -20, 'volume_m3' => '0,6']],
            ['kind' => 'coil', 'component_id' => $a, 'values' => ['pressure_operating_mpa' => 5, 'design_corrosion_mm_year' => '0,1'], 'qualifiers' => ['temperature_design_c' => ['state' => 'unknown']], 'verification' => ['temperature_design_c' => true]],
            ['kind' => 'coil', 'component_id' => $b, 'values' => ['pressure_operating_mpa' => 10]],
        ]];
        $saved = $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.passport.pressure_operating_mpa', 2)->assertJsonPath('data.operating_scopes.0.values.pressure_operating_mpa', 0)->assertJsonPath('data.operating_scopes.0.values.volume_m3', 0.6)
            ->assertJsonPath('data.operating_scopes.1.values.pressure_operating_mpa', 5)->assertJsonPath('data.operating_scopes.1.qualifiers.temperature_design_c.state', 'unknown')->assertJsonPath('data.operating_scopes.2.values.pressure_operating_mpa', 10)->json('data');
        unset($body['structure']);
        $body['version'] = 2;
        $body['operating_scopes'] = [['kind' => 'coil', 'component_id' => $a, 'values' => ['pressure_operating_mpa' => 7], 'verification' => ['pressure_operating_mpa' => true]]];
        $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.operating_scopes.1.checks.pressure_operating_mpa.by', $this->app['auth']->user()->id)->assertJsonPath('data.operating_scopes.2.values.pressure_operating_mpa', 10);
        $this->patchJson($url, $body)->assertConflict();
        $body['version'] = 3;
        $body['operating_scopes'][0]['values']['pressure_operating_mpa'] = 8;
        $body['operating_scopes'][0]['verification'] = [];
        $this->patchJson($url, $body)->assertOk()->assertJsonMissingPath('data.operating_scopes.1.checks.pressure_operating_mpa')->assertJsonPath('data.operating_scopes.1.checks.temperature_design_c.by', $this->app['auth']->user()->id);
        $body['version'] = 4;
        $body['operating_scopes'] = [['kind' => 'tube', 'component_id' => null, 'values' => ['pressure_installation_mpa' => 1]]];
        $this->patchJson($url, $body)->assertUnprocessable();
        $body['operating_scopes'] = [['kind' => 'coil', 'component_id' => $a, 'qualifiers' => ['temperature_design_c' => []]]];
        $this->patchJson($url, $body)->assertUnprocessable();
        $body['operating_scopes'] = [['kind' => 'coil', 'component_id' => $a, 'values' => ['pressure_operating_mpa' => null], 'qualifiers' => ['pressure_operating_mpa' => ['state' => 'not_applicable']]]];
        $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.operating_scopes.1.values.pressure_operating_mpa', null)->assertJsonPath('data.operating_scopes.1.qualifiers.pressure_operating_mpa.state', 'not_applicable');
        $body['version'] = 5;
        $body['operating_scopes'] = [['kind' => 'coil', 'component_id' => $a, 'values' => ['pressure_operating_mpa' => 9]]];
        $this->patchJson($url, $body)->assertOk()->assertJsonMissingPath('data.operating_scopes.1.qualifiers.pressure_operating_mpa')->assertJsonPath('data.operating_scopes.1.id', $saved['operating_scopes'][1]['id']);
        unset($body['operating_scopes']);
        $body['version'] = 6;
        $structure[0]['status'] = 'removed';
        $body['structure'] = [$structure[0]];
        $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.operating_scopes.1.active', false)->assertJsonPath('data.operating_scopes.1.values.pressure_operating_mpa', 9);
        unset($body['structure']);
        $body['version'] = 7;
        $body['operating_scopes'] = [['kind' => 'coil', 'component_id' => $a, 'values' => ['pressure_operating_mpa' => 8]]];
        $this->patchJson($url, $body)->assertUnprocessable();
        $body['operating_scopes'] = [['kind' => 'coil', 'component_id' => $b, 'values' => ['pressure_operating_mpa' => 11]], ['kind' => 'coil', 'component_id' => $b, 'values' => ['pressure_operating_mpa' => 12]]];
        $this->patchJson($url, $body)->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('data.version', 7)->assertJsonPath('data.operating_scopes.2.values.pressure_operating_mpa', 10);
        $other = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Другая ЕО']])->assertCreated()->json('data');
        $this->patchJson('/api/equipment/'.$other['id'], ['installation_id' => $installation, 'passport' => ['name' => 'Не должно сохраниться'], 'version' => 1, 'operating_scopes' => [['kind' => 'coil', 'component_id' => $b, 'values' => ['pressure_operating_mpa' => 1]]]])->assertNotFound();
        $this->getJson('/api/equipment/'.$other['id'])->assertJsonPath('data.version', 1)->assertJsonPath('data.passport.name', 'Другая ЕО');
        $this->patchJson('/api/equipment/'.$other['id'], ['installation_id' => $installation, 'passport' => ['name' => 'Другая ЕО'], 'version' => 1, 'operating_scopes' => [['kind' => 'tube', 'component_id' => null, 'values' => ['pressure_operating_mpa' => 1]]]])->assertUnprocessable();
        app(TenantContext::class)->set($tenant->refresh());
        try {
            $db = DB::connection('tenant');
            $this->assertSame(3, $db->table('equipment_operating_scopes')->where('equipment_id', $created['id'])->count());
            $this->assertEquals(10, $db->table('equipment_operating_scopes')->where('component_id', $b)->value('pressure_operating_mpa'));
            try {
                $db->transaction(fn () => $db->table('equipment_operating_scopes')->where('component_id', $b)->update(['component_id' => $a]));
                $this->fail('Scope identity must not be reassigned.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
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

    public function test_unknown_name_is_explicit_and_extended_heating_types_remain_typed(): void
    {
        $tenant = $this->setupTenant('unknown-name-heating');
        $this->login($tenant);
        $installation = $this->installation();
        $input = ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => null, 'tag' => 'Е-1', 'heating_type' => 'рубашка']];
        $this->postJson('/api/equipment', $input)->assertUnprocessable();
        $input['passport_qualifiers'] = ['name' => ['state' => 'unknown']];
        $id = $this->postJson('/api/equipment', $input)->assertCreated()->assertJsonPath('data.passport.name', null)->assertJsonPath('data.passport_qualifiers.name.state', 'unknown')->assertJsonPath('data.passport.heating_type', 'рубашка')->json('data.id');
        $this->getJson('/api/equipment?q='.urlencode('Е-1'))->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.passport.name', null);
        $body = ['installation_id' => $installation, 'passport' => $input['passport'], 'passport_qualifiers' => $input['passport_qualifiers'], 'version' => 1];
        foreach (['паровой спутник', 'электрообогрев'] as $type) {
            $body['passport']['heating_type'] = $type;
            $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.passport.heating_type', $type);
            $body['version']++;
        }
        $body['passport']['heating_type'] = 'неизвестный тип';
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $body['passport']['heating_type'] = 'рубашка';
        $body['passport_qualifiers']['name']['state'] = 'not_applicable';
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        app(TenantContext::class)->set($tenant);
        try {
            try {
                DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_passport_annotations')->where('equipment_id', $id)->where('field_key', 'name')->delete());
                $this->fail('Unknown equipment name cannot lose its explicit state.');
            } catch (\PDOException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
            try {
                DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_constructions')->where('equipment_id', $id)->update(['heating_type' => 'неизвестный тип']));
                $this->fail('Heating type must remain constrained in PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_import_validation_matches_http_normalization_and_rejects_malformed_nested_inputs(): void
    {
        $tenant = $this->setupTenant('equipment-input-contract');
        $this->login($tenant);
        $installation = $this->installation();
        $input = ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => ' Сосуд ', 'registration_number' => '001 РТН', 'pressure_operating_mpa' => '0,0', 'external_coating_applied_on' => '2019']];
        $saved = $this->postJson('/api/equipment', $input)->assertCreated()->json('data');
        app(TenantContext::class)->set($tenant);
        try {
            $validator = app(EquipmentInputValidation::class);
            $validated = $validator->validated($input);
            foreach ($validated['passport'] as $field => $value) {
                $this->assertEquals($value, $saved['passport'][$field], $field);
            }
            $this->assertSame($validated['passport_qualifiers'], $saved['passport_qualifiers']);
            foreach ([['passport_qualifiers' => null], ['passport_qualifiers' => ['external_coating_applied_on' => 'invalid']], ['thickness' => ['stages' => ['invalid']]], ['operating_scopes' => ['invalid']]] as $invalid) {
                try {
                    $validator->validated(array_replace($input, ['version' => 1], $invalid, ['profile_code' => null]), true);
                    $this->fail('Malformed import input must fail the same validation contract.');
                } catch (ValidationException $exception) {
                    $this->assertNotEmpty($exception->errors());
                }
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
        $this->postJson('/api/equipment', array_replace($input, ['passport_qualifiers' => null]))->assertUnprocessable();
    }

    public function test_equivalent_numbers_preserve_verification_but_text_identifiers_do_not(): void
    {
        $user = $this->login($this->setupTenant('passport-equality'));
        $installation = $this->installation();
        $passport = ['name' => 'Сосуд', 'pressure_design_mpa' => 0, 'registration_number' => '001'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $url = '/api/equipment/'.$id;
        $this->patchJson($url, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'verification' => ['pressure_design_mpa' => true, 'registration_number' => true]])->assertOk();
        $passport['pressure_design_mpa'] = 0.0;
        $this->json('PATCH', $url, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2], [], JSON_PRESERVE_ZERO_FRACTION)->assertOk()
            ->assertJsonPath('data.checks.pressure_design_mpa.by', $user->id)->assertJsonPath('data.checks.registration_number.by', $user->id);
        $passport['registration_number'] = '1';
        $this->patchJson($url, ['installation_id' => $installation, 'passport' => $passport, 'version' => 3])->assertOk()
            ->assertJsonMissingPath('data.checks.registration_number')->assertJsonPath('data.checks.pressure_design_mpa.by', $user->id);
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

    public function test_completed_diagnostics_assign_next_dates_and_share_one_plan_without_absorbed_overdue(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 9));
        $this->login($this->setupTenant('equipment-history-plan'));
        $installation = $this->installation();
        $passport = ['name' => 'Сосуд с историей'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $make = fn ($kind, $performed, $next, $other = []) => ['id' => (string) Str::uuid(), 'kind' => $kind, 'is_primary' => false, 'performed_on' => $performed, 'next_on' => $next, 'follow_up_dates' => $other];
        $epb = $make('ЭПБ', '2025-06-01', '2033-12-31', ['ТО-1' => '2026-10-01', 'ТО-2' => '2026-12-01', 'ГИ' => '2033-12-31']);
        $epb += ['organization' => 'Организация НК', 'inspection_type' => 'плановая', 'verified' => true];
        $old = $make('ТО-1', '2023-06-16', '2025-06-16');
        $hydro = $make('ГИ', '2025-06-01', '2033-12-31');
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'inspections' => [$old, $epb, $hydro]])->assertOk();
        $plan = $this->getJson('/api/equipment/'.$id)->assertOk()->json('data.diagnostics');
        $to1 = collect($plan)->firstWhere('kind', 'ТО-1');
        $this->assertSame('2025-06-01', $to1['performed_on']);
        $this->assertSame('ЭПБ', $to1['performed_by']);
        $this->assertSame('2026-10-01', $to1['planned_on']);
        $this->assertSame('ТО-2', $to1['absorbed_by']);
        $this->assertNull(collect($plan)->firstWhere('kind', 'ГИ')['absorbed_by']);
        $this->getJson('/api/diagnostic-schedule?plan_state=overdue')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/diagnostic-schedule?kind='.urlencode('ТО-1').'&plan_state=missing')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/diagnostic-schedule?plan_state=upcoming&within_days=30')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.diagnostics', $plan);
        $this->getJson('/api/equipment')->assertOk()->assertJsonPath('data.0.diagnostics', $plan);

        // A newer completed target inspection supersedes recommendations from an older EPB.
        $latest = $make('ТО-1', '2026-10-02', '2027-10-02');
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2, 'inspections' => [$latest]])->assertOk();
        $plan = $this->getJson('/api/equipment/'.$id)->assertOk()->json('data.diagnostics');
        $this->assertSame('2027-10-02', collect($plan)->firstWhere('kind', 'ТО-1')['planned_on']);
        // Declared unknown latest recommendation must not revive an obsolete older date.
        $newer = $make('ЭПБ', '2026-10-03', '2034-10-03', ['ТО-1' => null]);
        $newer['unknown_fields'] = ['ТО-1'];
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 3, 'inspections' => [$newer]])->assertOk();
        $plan = $this->getJson('/api/equipment/'.$id)->assertOk()->json('data.diagnostics');
        $this->assertNull(collect($plan)->firstWhere('kind', 'ТО-1')['planned_on']);
    }

    public function test_inspection_verification_unknown_values_and_thickness_date_lock_are_atomic(): void
    {
        $user = $this->login($this->setupTenant('equipment-history-lock'));
        $installation = $this->installation();
        $passport = ['name' => 'Сосуд'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $inspection = ['id' => (string) Str::uuid(), 'kind' => 'ЭПБ', 'is_primary' => false, 'performed_on' => '2025-06-01', 'next_on' => '2033-12-31', 'verified' => true];
        $write = fn ($version, $item, $extra = []) => $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => $version, 'inspections' => [$item]] + $extra);
        $write(1, $inspection)->assertOk();
        $inspection['organization'] = 'Изменение';
        $inspection['verified'] = false;
        $write(2, $inspection)->assertUnprocessable();
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 2);
        unset($inspection['organization']);
        $write(2, $inspection)->assertOk(); // Unlock separately.
        $inspection['organization'] = 'Изменение';
        $inspection['unknown_fields'] = ['performed_on'];
        $write(3, $inspection)->assertUnprocessable();
        $inspection['unknown_fields'] = ['organization'];
        $inspection['organization'] = null;
        $write(3, $inspection)->assertOk();
        $detail = $this->getJson('/api/equipment/'.$id)->assertOk()->json('data');
        $stage = collect($detail['thickness']['stages'])->firstWhere('inspection_id', $inspection['id']);
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 4,
            'thickness' => ['stages' => [['id' => $stage['id'], 'measured_on' => '2025-05-31', 'verified' => true, 'measurements' => []]]]])->assertOk();
        $inspection['performed_on'] = '2025-06-02';
        $write(5, $inspection)->assertUnprocessable();
        $saved = $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 5)->json('data.thickness.stages');
        $this->assertSame($user->id, collect($saved)->firstWhere('id', $stage['id'])['verified_by']);
    }

    public function test_legacy_diagnostic_histories_can_be_entered_through_api_and_match_card_dates(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/legacy-diagnostics.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(31, $fixture['records']);
        $this->login($this->setupTenant('legacy-diagnostic-parity'));
        $installation = $this->installation();
        $differences = [];
        foreach ($fixture['records'] as $input) {
            $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => $input['profile_code'], 'passport' => $input['passport']])->assertCreated()->json('data');
            $primary = collect($created['inspections'])->firstWhere('is_primary', true);
            $inspections = array_map(function ($row) use ($primary) {
                if ($row['is_primary']) {
                    $this->assertSame($primary['kind'], $row['kind']);
                }

                return ['id' => $row['is_primary'] ? $primary['id'] : (string) Str::uuid()] + $row;
            }, $input['inspections']);
            $updated = $this->patchJson('/api/equipment/'.$created['id'], ['installation_id' => $installation, 'passport' => $input['passport'], 'version' => 1, 'inspections' => $inspections])->assertOk()->json('data');
            foreach ($input['expected'] as $kind => $dates) {
                $matches = collect($updated['diagnostics'])->filter(fn ($row) => $kind === 'ГИ/ПИ' ? in_array($row['kind'], ['ГИ', 'ПИ'], true) : $row['kind'] === $kind);
                foreach ($dates as $field => $expected) {
                    $values = $matches->pluck($field)->filter()->sort()->values();
                    $actual = $field === 'performed_on' ? $values->last() : $values->first();
                    if ($actual !== $expected) {
                        $differences[] = ['equipment' => $input['legacy_id'], 'kind' => $kind, 'field' => $field, 'old_card' => $expected, 'api' => $actual];
                    }
                }
            }
        }
        $this->assertSame([], $differences, json_encode($differences, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function test_extended_passport_is_typed_relational_and_preserves_unknown_states_and_year_precision(): void
    {
        $tenant = $this->setupTenant('equipment-relational-passport');
        $user = $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'Сосуд с полным паспортом', 'tag' => 'Е-100', 'pressure_operating_mpa' => '0,0', 'temperature_mdmt_c' => '-40,5',
            'external_coating_applied_on' => '2019', 'external_coating_quality' => 'среднее', 'orientation' => 'горизонтальное', 'insulation_chlorides' => 'неизвестно', 'atmospheric_conditions' => 'мягкие'];
        $qualifiers = ['temperature_shutdown_trip_c' => ['state' => 'not_applicable']];
        $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport, 'passport_qualifiers' => $qualifiers])->assertCreated()
            ->assertJsonPath('data.passport.pressure_operating_mpa', 0)->assertJsonPath('data.passport.temperature_mdmt_c', -40.5)
            ->assertJsonPath('data.passport.external_coating_applied_on', '2019-01-01')->assertJsonPath('data.passport_qualifiers.external_coating_applied_on.precision', 'year')->json('data');
        $id = $created['id'];
        $body = ['installation_id' => $installation, 'passport' => $created['passport'], 'passport_qualifiers' => $created['passport_qualifiers'], 'version' => 1,
            'verification' => ['temperature_shutdown_trip_c' => true, 'external_coating_applied_on' => true]];
        $saved = $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonPath('data.checks.temperature_shutdown_trip_c.by', $user->id)->json('data');
        $body['version'] = 2;
        unset($body['verification']);
        $body['passport_qualifiers']['temperature_shutdown_trip_c']['state'] = 'unknown';
        $this->patchJson('/api/equipment/'.$id, $body)->assertOk()->assertJsonMissingPath('data.checks.temperature_shutdown_trip_c');
        $body['version'] = 3;
        $body['passport']['temperature_shutdown_trip_c'] = -50;
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        unset($body['passport']['temperature_shutdown_trip_c']);
        $body['passport']['external_coating_applied_on'] = '2019-02-02';
        $this->patchJson('/api/equipment/'.$id, $body)->assertUnprocessable();
        $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.version', 3);
        app(TenantContext::class)->set($tenant);
        try {
            $db = DB::connection('tenant');
            $this->assertEquals(-40.5, $db->table('equipment_operating_parameters')->where('equipment_id', $id)->value('temperature_mdmt_c'));
            $this->assertSame('2019-01-01', $db->table('equipment_protections')->where('equipment_id', $id)->value('external_coating_applied_on'));
            $this->assertSame('unknown', $db->table('equipment_passport_annotations')->where('equipment_id', $id)->where('field_key', 'temperature_shutdown_trip_c')->value('state'));
            $columns = $db->table('information_schema.columns')->where('table_schema', 'public')->where('table_name', 'equipment')->pluck('column_name')->all();
            $this->assertNotContains('passport', $columns);
            $this->assertNotContains('checks', $columns);
            $this->assertNotContains('passport_qualifiers', $columns);
        } finally {
            app(TenantContext::class)->set(null);
        }
    }

    public function test_nested_structure_is_topologically_saved_and_cycles_or_excess_depth_do_not_consume_codes(): void
    {
        $this->login($this->setupTenant('equipment-nested-structure'));
        $installation = $this->installation();
        $passport = ['name' => 'Вложенная сборка'];
        $id = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data.id');
        $root = (string) Str::uuid();
        $middle = (string) Str::uuid();
        $leaf = (string) Str::uuid();
        $make = fn ($id, $parent = null) => ['id' => $id, 'parent_id' => $parent, 'kind' => $parent ? 'element' : 'coil', 'is_coil' => $parent === null, 'name' => 'Элемент', 'status' => 'active', 'position' => 0];
        $result = $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1,
            'structure' => [$make($leaf, $middle), $make($middle, $root) + ['nominal_thickness_mm' => 4.8], $make($root)],
            'thickness' => ['cmls' => [['id' => (string) Str::uuid(), 'component_id' => $leaf, 'point_count' => 2]]]])->assertOk()->json('data');
        $this->assertSame('K-01.S1.S1', collect($result['components'])->firstWhere('id', $leaf)['code']);
        $element = collect($result['condition']['elements'])->firstWhere('id', $leaf);
        $this->assertSame($root, $element['scope_id']);
        $this->assertSame(4.8, $element['nominal_mm']);
        $a = (string) Str::uuid();
        $b = (string) Str::uuid();
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2, 'structure' => [$make($a, $b), $make($b, $a)]])->assertUnprocessable();
        $chain = [];
        $parent = $root;
        for ($index = 0; $index < 8; $index++) {
            $next = (string) Str::uuid();
            $chain[] = $make($next, $parent);
            $parent = $next;
        }
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2, 'structure' => $chain])->assertUnprocessable();
        $new = (string) Str::uuid();
        $saved = $this->patchJson('/api/equipment/'.$id, ['installation_id' => $installation, 'passport' => $passport, 'version' => 2, 'structure' => [$make($new, $root)]])->assertOk()->json('data.components');
        $this->assertSame('K-01.S2', collect($saved)->firstWhere('id', $new)['code']);
    }

    public function test_scope_protection_monitoring_and_date_precision_are_typed_independent_and_guarded_in_sql(): void
    {
        $tenant = $this->setupTenant('scope-protection');
        $this->login($tenant);
        $installation = $this->installation();
        $passport = ['name' => 'КТТ', 'external_coating_present' => 'присутствует', 'temperature_max_c' => 10];
        $created = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_teplo', 'passport' => $passport])->assertCreated()->json('data');
        $url = '/api/equipment/'.$created['id'];
        $head = (string) Str::uuid();
        $coil = (string) Str::uuid();
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => 1,
            'structure' => [['id' => $head, 'parent_id' => null, 'kind' => 'head', 'name' => 'Днище ТП', 'flow_space' => 'tube', 'is_coil' => false, 'status' => 'active', 'position' => 0],
                ['id' => $coil, 'parent_id' => null, 'kind' => 'coil', 'name' => 'Змеевик', 'is_coil' => true, 'status' => 'active', 'position' => 1]],
            'operating_scopes' => [['kind' => 'tube', 'component_id' => null, 'values' => ['temperature_max_c' => 150, 'external_coating_present' => 'отсутствует', 'insulation_present' => 'присутствует', 'external_coating_applied_on' => '2020', 'external_coating_life_years' => 0, 'corrosion_monitoring' => 'зонды электрического сопротивления', 'design_corrosion_mm_year' => '0,1'], 'verification' => ['external_coating_applied_on' => true]],
                ['kind' => 'coil', 'component_id' => $coil, 'values' => ['flow_velocity_m_s' => 0, 'external_coating_present' => 'присутствует', 'insulation_present' => 'отсутствует']]]];
        $saved = $this->patchJson($url, $body)->assertOk()->json('data');
        $this->assertSame('2020-01-01', $saved['operating_scopes'][0]['values']['external_coating_applied_on']);
        $this->assertSame('year', $saved['operating_scopes'][0]['qualifiers']['external_coating_applied_on']['precision']);
        $this->assertSame('зонды электрического сопротивления', $saved['operating_scopes'][0]['values']['corrosion_monitoring']);
        $this->assertSame(0, $saved['operating_scopes'][1]['values']['flow_velocity_m_s']);
        $this->assertSame(0, $saved['operating_scopes'][0]['values']['external_coating_life_years']);
        $visual = array_column($saved['visual_inspections']['elements'], null, 'component_id');
        $this->assertFalse($visual[$head]['coating_applicable']);
        $this->assertTrue($visual[$head]['insulated']);
        $this->assertSame('passport', $visual[$head]['insulation_source']);
        $this->assertTrue($visual[$coil]['coating_applicable']);
        $this->assertFalse($visual[$coil]['insulated']);
        $body = ['installation_id' => $installation, 'passport' => $passport, 'version' => $saved['version'], 'operating_scopes' => [['kind' => 'tube', 'component_id' => null, 'values' => ['external_coating_applied_on' => '2020-02-02'], 'qualifiers' => ['external_coating_applied_on' => ['precision' => 'year']]]]];
        $this->patchJson($url, $body)->assertUnprocessable();
        app(TenantContext::class)->set($tenant);
        $db = DB::connection('tenant');
        foreach (['external_coating_present' => 'invented', 'external_coating_life_years' => -1, 'external_coating_applied_on' => '2020-02-02'] as $field => $value) {
            try {
                $db->transaction(function () use ($db, $saved, $field, $value) {
                    $db->table('equipment_operating_scopes')->where('id', $saved['operating_scopes'][0]['id'])->update([$field => $value]);
                    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('Invalid scope value must fail SQL guards.');
            } catch (QueryException $error) {
                $this->assertSame('23514', $error->getCode());
            }
        }
        $migration = require database_path('tenant-migrations/2026_11_10_000068_extend_operating_scope_protection_and_rbi_fields.php');
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        try {
            $migration->down();
            $this->fail('Populated independent protection inputs must not be discarded.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot discard', $error->getMessage());
        } finally {
            DB::setDefaultConnection($previous);
        }
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
