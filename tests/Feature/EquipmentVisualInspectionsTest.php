<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Diagnostics\Application\EquipmentVisualInspections;
use App\Modules\Diagnostics\Application\VisualInspectionInputValidation;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EquipmentVisualInspectionsTest extends TestCase
{
    use DatabaseMigrations;

    private Tenant $tenant;

    private User $actor;

    private array $parts;

    private string $equipment;

    private function fixture(): void
    {
        $this->tenant = $this->setupTenant('visual-facts');
        $this->actor = $this->login($this->tenant);
        $installation = $this->installation();
        $passport = ['name' => 'ВИК'];
        $equipment = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => $passport])->assertCreated()->json('data');
        $this->equipment = $equipment['id'];
        $this->parts = array_combine(['parent', 'leaf', 'head', 'coil'], array_map(fn () => (string) Str::uuid(), range(1, 4)));
        $make = fn ($id, $parent, $kind, $coil) => ['id' => $id, 'parent_id' => $parent, 'kind' => $kind, 'name' => $kind, 'is_coil' => $coil, 'status' => 'active', 'position' => 0];
        $structure = [$make($this->parts['parent'], null, 'shell', false), $make($this->parts['leaf'], $this->parts['parent'], 'element', false), $make($this->parts['head'], null, 'head', false), $make($this->parts['coil'], null, 'coil', true)];
        $primary = $equipment['inspections'][0];
        $inspections = [['id' => $primary['id'], 'kind' => $primary['kind'], 'is_primary' => true, 'performed_on' => '2023-06-16'], ['id' => (string) Str::uuid(), 'kind' => 'ЭПБ', 'is_primary' => false, 'performed_on' => '2023-06-16']];
        $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $installation, 'passport' => $passport, 'version' => 1, 'structure' => $structure, 'inspections' => $inspections])->assertOk();
        app(TenantContext::class)->set($this->tenant);
    }

    private function read(): array
    {
        return app(EquipmentVisualInspections::class)->read(Equipment::findOrFail($this->equipment));
    }

    private function write(array $input): void
    {
        $input = ['schema_version' => 'visual-inspections-v1', ...$input];
        $normalized = app(VisualInspectionInputValidation::class)->normalize($input);
        $validated = Validator::make(['visual_inspections' => $normalized], VisualInspectionInputValidation::rules())->validate()['visual_inspections'];
        app(TenantWrites::class)->transaction(fn () => app(EquipmentVisualInspections::class)->apply(Equipment::findOrFail($this->equipment), $validated, $this->actor->id));
    }

    private function row(string $part, array $values): array
    {
        return ['component_id' => $this->parts[$part], 'values' => $values];
    }

    public function test_read_only_calendar_has_stable_virtual_ids_and_only_saved_values_become_observations(): void
    {
        $this->fixture();
        $db = DB::connection('tenant');
        $view = $this->read();
        $this->assertCount(1, $view['stages']);
        $stage = $view['stages'][0];
        $this->assertSame('ЭПБ', $stage['diagnostic_kind']);
        $this->assertNull($stage['measured_on']);
        $this->assertFalse($stage['persisted']);
        $this->assertSame([], $stage['observations']);
        $this->assertSame($stage['id'], $this->read()['stages'][0]['id']);
        $this->assertSame(0, $db->table('equipment_visual_stages')->count());
        $this->write(['stages' => [['id' => $stage['id'], 'inspection_id' => $stage['inspection_id'], 'measured_on' => '2023-06-15', 'observations' => [$this->row('leaf', ['corrosion_present' => true, 'depth_mm' => '0', 'affected_area' => 0, 'area_unit' => 'percent', 'cracks_present' => false, 'partial' => false]), $this->row('head', ['corrosion_present' => false, 'coating_condition' => 'high']), $this->row('coil', ['corrosion_present' => null, 'insulation_condition' => 'average'])]]], 'insulation_overrides' => [['component_id' => $this->parts['coil'], 'is_insulated' => false]]]);
        $saved = $this->read();
        $observations = array_column($saved['stages'][0]['observations'], 'values', 'component_id');
        $this->assertCount(3, $observations);
        $this->assertSame(0.0, $observations[$this->parts['leaf']]['depth_mm']);
        $this->assertSame(false, $observations[$this->parts['leaf']]['cracks_present']);
        $this->assertNull($observations[$this->parts['leaf']]['coating_condition']);
        $this->assertNull($observations[$this->parts['coil']]['corrosion_present']);
        $this->assertSame([['component_id' => $this->parts['coil'], 'is_insulated' => false]], $saved['insulation_overrides']);
        $this->assertSame($stage['id'], $saved['stages'][0]['id']);
        $this->assertTrue($saved['stages'][0]['persisted']);
        $this->write(['insulation_overrides' => [['component_id' => $this->parts['coil'], 'is_insulated' => null]]]);
        $this->assertSame([], $this->read()['insulation_overrides']);
    }

    public function test_verified_stages_bind_facts_and_diagnostic_dates_until_explicit_unlock(): void
    {
        $this->fixture();
        $stage = $this->read()['stages'][0];
        $db = DB::connection('tenant');
        $this->write(['stages' => [['id' => $stage['id'], 'inspection_id' => $stage['inspection_id'], 'measured_on' => '2023-06-15', 'verified' => true, 'observations' => [$this->row('leaf', ['corrosion_present' => true, 'depth_mm' => .2])]]]]);
        $checked = $this->read()['stages'][0];
        $this->assertTrue($checked['verified']);
        $this->assertSame($this->actor->id, $checked['verified_by']);
        try {
            $this->write(['stages' => [['id' => $stage['id'], 'observations' => [$this->row('leaf', ['depth_mm' => .3])]]]]);
            $this->fail('Checked data needs an explicit unlock.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('visual_inspections.stages.0', $exception->errors());
        }
        foreach (['observation', 'diagnostic', 'stage_date', 'check_actor'] as $change) {
            try {
                $db->transaction(function () use ($db, $stage, $change) {
                    if ($change === 'observation') {
                        $db->table('equipment_visual_observations')->where('stage_id', $stage['id'])->update(['depth_mm' => .3]);
                    } elseif ($change === 'diagnostic') {
                        $db->table('equipment_inspections')->where('id', $stage['inspection_id'])->update(['performed_on' => '2023-06-17']);
                    } elseif ($change === 'stage_date') {
                        $db->table('equipment_visual_stages')->where('id', $stage['id'])->update(['measured_on' => '2023-06-14']);
                    } else {
                        $db->table('equipment_visual_stages')->where('id', $stage['id'])->update(['verified_by' => $this->actor->id + 1]);
                    }
                });
                $this->fail('PostgreSQL must reject mutation of checked visual facts.');
            } catch (\PDOException $exception) {
                $this->assertSame('23514', (string) $exception->getCode());
            }
        }
        $this->write(['stages' => [['id' => $stage['id'], 'verified' => true, 'unlock' => true, 'observations' => [$this->row('leaf', ['depth_mm' => .3])]]]]);
        $this->assertSame(.3, $this->read()['stages'][0]['observations'][0]['values']['depth_mm']);
        $this->write(['stages' => [['id' => $stage['id'], 'verified' => false, 'observations' => [$this->row('leaf', ['corrosion_present' => false])]]]]);
        $values = $this->read()['stages'][0]['observations'][0]['values'];
        $this->assertFalse($values['corrosion_present']);
        $this->assertNull($values['depth_mm']);
        $this->write(['stages' => [['id' => $stage['id'], 'reset_components' => [$this->parts['leaf']]]]]);
        $this->assertSame([], $this->read()['stages'][0]['observations']);
    }

    public function test_later_invalid_observation_rolls_back_every_stage_and_owner_rules_reject_containers(): void
    {
        $this->fixture();
        $stage = $this->read()['stages'][0];
        $manual = (string) Str::uuid();
        $db = DB::connection('tenant');
        foreach ([$this->parts['parent'], (string) Str::uuid()] as $invalid) {
            try {
                $this->write(['stages' => [['id' => $manual, 'reference_date' => '2024-01-01', 'measured_on' => '2024-01-01', 'observations' => [$this->row('head', ['corrosion_present' => false])]], ['id' => $stage['id'], 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => $invalid, 'values' => ['corrosion_present' => false]]]]]]);
                $this->fail('Missing or container ownership must roll back the first stage.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('visual_inspections.stages.1.observations', $exception->errors());
            }
            $this->assertSame(0, $db->table('equipment_visual_stages')->count());
            $this->assertSame(0, $db->table('equipment_visual_observations')->count());
        }
        $this->write(['stages' => [['id' => $manual, 'reference_date' => '2024-01-01', 'measured_on' => '2024-01-01', 'observations' => [$this->row('head', ['corrosion_present' => false])]]]]);
        $this->assertCount(2, $this->read()['stages']);
        $this->write(['delete_stage_ids' => [$manual]]);
        $this->assertCount(1, $this->read()['stages']);
        $this->assertSame(0, $db->table('equipment_visual_stages')->count());
    }

    public function test_sql_preserves_visual_owners_field_applicability_and_unique_diagnostic_groups(): void
    {
        $this->fixture();
        $stage = $this->read()['stages'][0];
        $db = DB::connection('tenant');
        $manual = (string) Str::uuid();
        $this->write(['stages' => [['id' => $stage['id'], 'inspection_id' => $stage['inspection_id'], 'measured_on' => '2023-06-15', 'observations' => [$this->row('leaf', ['corrosion_present' => true, 'depth_mm' => .2])]], ['id' => $manual, 'reference_date' => '2024-01-01', 'measured_on' => '2024-01-01']], 'insulation_overrides' => [['component_id' => $this->parts['head'], 'is_insulated' => false]]]);
        $model = Equipment::findOrFail($this->equipment);
        $other = app(EquipmentCommands::class)->create(['installation_id' => $model->installation_id, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Другая ЕО']], $this->actor->id);
        foreach (['owner', 'identity', 'override', 'origin', 'area', 'applicability', 'date_group', 'empty_date', 'check_pair'] as $change) {
            try {
                $db->transaction(function () use ($db, $stage, $change, $other) {
                    if ($change === 'owner') {
                        $db->table('equipment_visual_observations')->insert(['id' => (string) Str::uuid(), 'stage_id' => $stage['id'], 'equipment_id' => $other->id, 'component_id' => $this->parts['head'], 'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
                    } elseif ($change === 'identity') {
                        $db->table('equipment_visual_observations')->where('stage_id', $stage['id'])->update(['component_id' => $this->parts['head']]);
                    } elseif ($change === 'override') {
                        $db->table('equipment_visual_insulation_overrides')->update(['component_id' => $this->parts['leaf']]);
                    } elseif ($change === 'origin') {
                        $db->table('equipment_visual_stages')->where('id', $stage['id'])->update(['inspection_id' => null, 'reference_date' => '2023-06-16']);
                    } elseif ($change === 'area') {
                        $db->table('equipment_visual_observations')->where('stage_id', $stage['id'])->update(['affected_area' => 101, 'area_unit' => 'percent']);
                    } elseif ($change === 'applicability') {
                        $db->table('equipment_visual_observations')->where('stage_id', $stage['id'])->update(['corrosion_present' => false]);
                    } elseif ($change === 'date_group') {
                        $db->table('equipment_inspections')->where('id', $stage['inspection_id'])->update(['performed_on' => '2024-01-01']);
                    } elseif ($change === 'empty_date') {
                        $db->table('equipment_inspections')->where('id', $stage['inspection_id'])->update(['performed_on' => null]);
                    } else {
                        $db->table('equipment_visual_stages')->where('id', $stage['id'])->update(['verified_by' => $this->actor->id]);
                    }
                });
                $this->fail('SQL must reject '.$change);
            } catch (\PDOException $exception) {
                $this->assertSame($change === 'owner' ? '23503' : '23514', (string) $exception->getCode(), $change.': '.$exception->getMessage());
            }
        }
        $this->assertCount(2, $this->read()['stages']);
        $this->assertSame(.2, $this->read()['stages'][0]['observations'][0]['values']['depth_mm']);
    }

    public function test_public_card_api_exposes_visual_facts_with_roles_versions_and_atomic_diagnostic_reset(): void
    {
        $this->fixture();
        $model = Equipment::findOrFail($this->equipment);
        $url = '/api/equipment/'.$model->id;
        $base = ['installation_id' => $model->installation_id, 'passport' => $model->passport, 'version' => $model->version];
        $read = $this->getJson($url)->assertOk()->json('data.visual_inspections');
        $this->assertSame('visual-inspections-v1', $read['schema_version']);
        $this->assertCount(3, $read['elements']);
        $this->getJson('/api/equipment/catalog')->assertOk()->assertJsonPath('visual_inspection_schema.version', 'visual-inspections-v1');
        $stage = $read['stages'][0];
        $input = ['schema_version' => 'visual-inspections-v1', 'stages' => [['id' => $stage['id'], 'inspection_id' => $stage['inspection_id'], 'measured_on' => '2023-06-15', 'observations' => [$this->row('leaf', ['corrosion_present' => true, 'depth_mm' => '0,2', 'partial' => 0])]]], 'insulation_overrides' => [['component_id' => $this->parts['leaf'], 'is_insulated' => 0]]];
        $saved = $this->patchJson($url, $base + ['visual_inspections' => $input])->assertOk()->assertJsonPath('data.version', 3)->json('data.visual_inspections');
        $this->assertSame('B', $saved['stages'][0]['observations'][0]['category']);
        $this->assertSame('ext', $saved['stages'][0]['observations'][0]['kind']);
        $this->assertFalse($saved['stages'][0]['observations'][0]['values']['partial']);
        $this->patchJson($url, $base + ['visual_inspections' => $input])->assertConflict();
        $base['version'] = 3;
        foreach ([[], ['schema_version' => 'visual-inspections-v1', 'stages' => 'bad'], ['schema_version' => 'visual-inspections-v1', 'stages' => [['id' => $stage['id'], 'observations' => [['component_id' => $this->parts['leaf'], 'values' => ['verified_by' => 1]]]]]]] as $bad) {
            $this->patchJson($url, $base + ['visual_inspections' => $bad])->assertUnprocessable();
        }
        $this->login($this->tenant, 'member');
        $this->getJson($url)->assertOk();
        $this->patchJson($url, $base + ['visual_inspections' => $input])->assertForbidden();
        $reviewer = $this->login($this->tenant);
        $verified = ['schema_version' => 'visual-inspections-v1', 'stages' => [['id' => $stage['id'], 'verified' => true]]];
        $this->patchJson($url, $base + ['visual_inspections' => $verified])->assertOk()->assertJsonPath('data.version', 4)->assertJsonPath('data.visual_inspections.stages.0.verified_by', $reviewer->id);
        $base['version'] = 4;
        $inspection = ['id' => $stage['inspection_id'], 'is_primary' => false, 'kind' => 'ЭПБ', 'performed_on' => null];
        $this->patchJson($url, $base + ['inspections' => [$inspection]])->assertUnprocessable();
        $this->patchJson($url, $base + ['inspections' => [$inspection], 'visual_inspections' => ['schema_version' => 'visual-inspections-v1', 'delete_stage_ids' => [$stage['id']]]])->assertUnprocessable();
        $this->patchJson($url, $base + ['visual_inspections' => ['schema_version' => 'visual-inspections-v1', 'stages' => [['id' => $stage['id'], 'verified' => false]]]])->assertOk()->assertJsonPath('data.version', 5);
        $base['version'] = 5;
        $reset = $this->patchJson($url, $base + ['inspections' => [$inspection], 'visual_inspections' => ['schema_version' => 'visual-inspections-v1', 'delete_stage_ids' => [$stage['id']]]])->assertOk()->assertJsonPath('data.version', 6)->json('data.visual_inspections');
        $this->assertCount(1, $reset['stages']);
        $this->assertSame('ТО-2', $reset['stages'][0]['diagnostic_kind']);
        $this->assertSame([], $reset['stages'][0]['observations']);
        app(TenantContext::class)->set($this->tenant);
        $db = DB::connection('tenant');
        $event = $db->table('domain_events')->where('entity_type', 'equipment')->where('entity_id', $model->id)->orderByDesc('id')->first();
        $before = json_decode($event->before, true);
        $this->assertSame(.2, $before['visual_inspections']['stages'][0]['observations'][0]['values']['depth_mm']);
        $this->assertSame(0, $db->table('equipment_visual_stages')->count());
    }

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
