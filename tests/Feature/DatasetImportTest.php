<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Models\Substance;
use App\Modules\DataExchange\Application\DatasetImport;
use App\Modules\DataExchange\Application\DatasetManifest;
use App\Modules\DataExchange\Application\EquipmentOrigins;
use App\Modules\DataExchange\Application\InstallationImportData;
use App\Modules\Diagnostics\Application\EquipmentInspectionCategories;
use App\Modules\Diagnostics\Application\EquipmentVisualInspections;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Infrastructure\MechanismFacts;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Installations\Application\InstallationCostInputValidation;
use App\Modules\Installations\Application\InstallationCosts;
use App\Modules\Installations\Application\InstallationMaintenance;
use App\Modules\Installations\Application\InstallationMaintenanceInputValidation;
use App\Modules\Installations\Models\Installation;
use App\Modules\ProcessFlows\Application\ProcessFlows;
use App\Modules\ProcessFlows\Domain\MixtureCalculator;
use App\Modules\ProcessFlows\Infrastructure\FlowCompositionResolver;
use App\Modules\ProcessFlows\Models\ProcessFlowNode;
use App\Modules\Rbi\Application\InstallationManagement;
use App\Modules\Rbi\Application\InstallationRiskCriteria;
use App\Modules\Rbi\Application\ManagementInputValidation;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DatasetImportTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function tenant(User $actor, string $slug, string $role = 'owner'): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => $slug, 'auth_config' => ['local' => ['enabled' => true]]]);
        $this->tenants[] = $tenant;
        app(TenantDatabaseManager::class)->provision($tenant);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $actor->id, 'role' => $role, 'auth_provider' => 'local']);
        app(TenantContext::class)->set($tenant->refresh());

        return $tenant;
    }

    private function manifest(): array
    {
        [$enterprise, $site, $production, $installation, $equipment, $component, $cml, $inspection] = array_map(fn () => (string) Str::uuid(), range(1, 8));

        return ['format' => 'rline-dataset-v1', 'source' => ['kind' => 'stored_snapshot', 'version' => 'test-v1', 'label' => 'Тестовый эталон', 'snapshot_at' => '2026-10-28T15:26:07.619Z', 'checksums' => ['fixture.json' => str_repeat('a', 64)]],
            'nodes' => [
                ['id' => $enterprise, 'input' => ['kind' => 'enterprise', 'name' => 'Предприятие', 'parent_id' => null]],
                ['id' => $site, 'input' => ['kind' => 'site', 'name' => 'Площадка', 'parent_id' => $enterprise]],
                ['id' => $production, 'input' => ['kind' => 'production', 'name' => 'Производство', 'parent_id' => $site]],
            ], 'installations' => [['id' => $installation, 'input' => ['name' => 'Установка', 'parent_id' => $production, 'passport' => ['area' => 0]]]],
            'equipment' => [['id' => $equipment, 'source_id' => 'EQ0001', 'create' => ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Сосуд', 'pressure_design_mpa' => 0, 'registration_number' => '001']],
                'components' => [['id' => $component, 'code' => 'K-03', 'parent_id' => null, 'kind' => 'shell', 'type_code' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0, 'construction' => ['nominal_pressure_mpa' => 0]]],
                'inspections' => [['source_reference' => 'old:EQ0001/diag/1', 'requested_id' => $inspection, 'input' => ['kind' => 'ТО-2', 'is_primary' => false, 'performed_on' => '2020-01-01', 'next_on' => '2028-01-01']]],
                'thickness' => ['cmls' => [['id' => $cml, 'component_id' => $component, 'code' => 'CML-03.7', 'point_count' => null]], 'stages' => [['source_date' => '2020-01-01', 'measured_on' => '2019-12-31', 'source_verified' => true, 'measurements' => [['cml_id' => $cml, 'points' => [12.123456789, null, 0]]]]]],
                'evidence' => ['original_check' => true],
            ]], 'issues' => []];
    }

    public function test_visual_import_resolves_diagnostics_keeps_unknowns_and_never_adopts_old_checks(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-visual');
        $manifest = $this->manifest();
        $component = $manifest['equipment'][0]['components'][0]['id'];
        $stages = [
            ['id' => (string) Str::uuid(), 'source_date' => '2020-01-01', 'measured_on' => '2019-12-31', 'source_verified' => true,
                'observations' => [['component_id' => $component, 'values' => ['corrosion_present' => true, 'depth_mm' => 0, 'cracks_present' => false]]]],
            ['id' => (string) Str::uuid(), 'source_date' => '2024-03-01', 'measured_on' => null, 'source_verified' => null,
                'observations' => [['component_id' => $component, 'values' => ['corrosion_present' => null, 'notes' => 'Неизвестно']]]],
        ];
        $manifest['equipment'][0]['visual_inspections'] = ['stages' => $stages, 'insulation_overrides' => [['component_id' => $component, 'is_insulated' => false]]];
        $manifest['equipment'][0]['evidence']['visual_checks'] = $stages;
        $service = app(DatasetImport::class);
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(2, $preview['counts']['visual_stages']);
        $this->assertSame(2, $preview['counts']['visual_observations']);
        $this->assertSame(0, DB::connection('tenant')->table('equipment_visual_stages')->count());
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $this->assertSame(1, $report['counts']['visual_insulation_overrides']);
        $equipment = Equipment::findOrFail($manifest['equipment'][0]['id']);
        $read = app(EquipmentVisualInspections::class)->read($equipment);
        $actual = array_column($read['stages'], null, 'id');
        $first = $actual[$stages[0]['id']];
        $this->assertNotNull($first['inspection_id']);
        $this->assertNull($first['reference_date']);
        $this->assertFalse($first['verified']);
        $this->assertNull($first['verified_by']);
        $this->assertEquals(0, $first['observations'][0]['values']['depth_mm']);
        $this->assertFalse($first['observations'][0]['values']['cracks_present']);
        $manual = $actual[$stages[1]['id']];
        $this->assertNull($manual['inspection_id']);
        $this->assertSame('2024-03-01', $manual['reference_date']);
        $this->assertNull($manual['measured_on']);
        $this->assertNull($manual['observations'][0]['values']['cracks_present']);
        $this->assertNull($manual['observations'][0]['values']['corrosion_present']);
        $replay = $service->apply($manifest, $actor->id);
        $this->assertTrue($replay['replayed']);
        $this->assertSame(2, DB::connection('tenant')->table('equipment_visual_observations')->count());
    }

    public function test_invalid_visual_owner_and_smuggled_current_verification_roll_back_everything(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-visual-invalid');
        $manifest = $this->manifest();
        $stage = ['id' => (string) Str::uuid(), 'source_date' => '2020-01-01', 'measured_on' => '2020-01-01', 'verified' => true];
        $manifest['equipment'][0]['visual_inspections'] = ['stages' => [$stage]];
        $service = app(DatasetImport::class);
        try {
            $service->apply($manifest, $actor->id);
            $this->fail('Source cannot set current verification.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertNoBusinessWrites();
        unset($stage['verified']);
        $stage['observations'] = [['component_id' => (string) Str::uuid(), 'values' => ['corrosion_present' => false]]];
        $manifest['equipment'][0]['visual_inspections']['stages'] = [$stage];
        try {
            $service->preview($manifest, $actor->id);
            $this->fail('Foreign element cannot own observations.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_category_import_reloads_sparse_facts_and_preserves_later_manual_edits_on_replay(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-categories');
        $manifest = $this->manifest();
        $component = $manifest['equipment'][0]['components'][0]['id'];
        $stage = ['id' => (string) Str::uuid(), 'group_code' => 'scc', 'source_date' => '2020-01-01', 'observations' => [['component_id' => $component, 'category' => 'D', 'cracks_present' => false]]];
        $empty = ['id' => (string) Str::uuid(), 'group_code' => 'hic', 'source_date' => '2024-01-01', 'observations' => [['component_id' => $component, 'category' => null, 'cracks_present' => null]]];
        $manifest['equipment'][0]['inspection_categories'] = ['stages' => [$stage, $empty]];
        $manifest['equipment'][0]['visual_inspections'] = ['stages' => [['id' => (string) Str::uuid(), 'source_date' => '2020-01-01', 'measured_on' => null, 'observations' => [['component_id' => $component, 'values' => ['corrosion_present' => null]]]]]];
        $service = app(DatasetImport::class);
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(2, $preview['counts']['inspection_category_stages']);
        $this->assertSame(1, $preview['counts']['inspection_category_observations']);
        $this->assertSame(0, $preview['counts']['visual_observations']);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $this->assertSame(1, $report['counts']['inspection_category_observations']);
        $equipment = Equipment::findOrFail($manifest['equipment'][0]['id']);
        $facts = app(EquipmentInspectionCategories::class)->read($equipment);
        $actual = collect($facts['stages'])->firstWhere('id', $stage['id']);
        $this->assertNotNull($actual['inspection_id']);
        $this->assertFalse($actual['observations'][0]['cracks_present']);
        app(EquipmentCommands::class)->update($equipment->id, ['installation_id' => $equipment->installation_id, 'passport' => $equipment->passport, 'version' => $equipment->version,
            'inspection_categories' => ['schema_version' => 'inspection-categories-v1', 'stages' => [['id' => $stage['id'], 'group_code' => 'scc', 'observations' => [['component_id' => $component, 'category' => 'B']]]]]], $actor->id);
        $this->assertTrue($service->apply($manifest, $actor->id)['replayed']);
        $this->assertSame('B', DB::connection('tenant')->table('equipment_inspection_category_observations')->value('category'));
    }

    public function test_mechanism_import_reads_back_typed_unknown_false_and_zero_without_accepting_old_reviews(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-mechanisms');
        $manifest = $this->manifest();
        $component = $manifest['equipment'][0]['components'][0]['id'];
        $manifest['equipment'][0]['mechanisms'] = ['schema_version' => 'component-screening-v1',
            'inputs' => ['vibration_present' => false, 'hydrogen_partial_pressure_mpa' => 0],
            'component_inputs' => [['component_id' => $component, 'tank_floor' => false]]];
        $service = app(DatasetImport::class);
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(1, $preview['counts']['mechanism_inputs']);
        $this->assertSame(1, $preview['counts']['component_mechanism_inputs']);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $this->assertSame(1, $report['counts']['mechanism_inputs']);
        $equipment = Equipment::findOrFail($manifest['equipment'][0]['id']);
        $facts = app(MechanismFacts::class)->read($equipment->id);
        $this->assertFalse($facts['inputs']['vibration_present']);
        $this->assertSame(0.0, $facts['inputs']['hydrogen_partial_pressure_mpa']);
        $this->assertNull($facts['inputs']['vibration_notes']);
        $this->assertFalse($facts['component_inputs'][0]['tank_floor']);
        $this->assertSame([], $facts['reviews']);
        app(EquipmentCommands::class)->update($equipment->id, ['installation_id' => $equipment->installation_id, 'passport' => $equipment->passport, 'version' => $equipment->version,
            'mechanisms' => ['schema_version' => 'component-screening-v1', 'inputs' => ['vibration_present' => true]]], $actor->id);
        $this->assertTrue($service->apply($manifest, $actor->id)['replayed']);
        $this->assertTrue(app(MechanismFacts::class)->read($equipment->id)['inputs']['vibration_present']);
        $manifest['equipment'][0]['mechanisms']['reviews'] = [];
        try {
            $service->preview($manifest, $actor->id);
            $this->fail('Source confirmations must not become current approvals.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('equipment.0.mechanisms', $error->errors());
        }
    }

    public function test_more_than_one_thousand_points_keep_boundary_values_and_explicit_skips(): void
    {
        $actor = User::factory()->create();
        $tenant = $this->tenant($actor, 'dataset-point-boundary');
        app(TenantContext::class)->set($tenant);
        $manifest = $this->manifest();
        $equipment = &$manifest['equipment'][0];
        $component = $equipment['components'][0]['id'];
        $equipment['thickness']['cmls'] = [];
        $equipment['thickness']['stages'][0]['measurements'] = [];
        $expected = [];
        for ($i = 0; $i < 11; $i++) {
            $id = (string) Str::uuid();
            $equipment['thickness']['cmls'][] = ['id' => $id, 'component_id' => $component, 'code' => 'CML-03.'.($i + 1), 'point_count' => 100];
            $points = array_map(fn ($point) => 10 + $i / 10 + $point / 1000, range(0, 99));
            if ($i === 9) {
                $points[99] = 0;
            }
            if ($i === 10) {
                $points[0] = null;
                $points[1] = 6.123456789;
            }
            $expected[$id] = $points;
            $equipment['thickness']['stages'][0]['measurements'][] = ['cml_id' => $id, 'points' => $points];
        }
        unset($equipment);
        $import = app(DatasetImport::class);
        $preview = $import->preview($manifest, $actor->id);
        $this->assertSame(1100, $preview['counts']['points']);
        $this->assertNoBusinessWrites();
        $report = $import->apply($manifest, $actor->id);
        $this->assertSame(1100, $report['counts']['points']);
        $this->assertSame(1100, DB::connection('tenant')->table('equipment_thickness_points')->count());
        foreach (DB::connection('tenant')->table('equipment_thickness_measurements')->get() as $measurement) {
            $points = json_decode($measurement->points, true);
            $this->assertCount(100, $points);
            foreach ($expected[$measurement->cml_id] as $index => $value) {
                if ($value === null) {
                    $this->assertNull($points[$index]);
                } else {
                    $this->assertNotNull($points[$index]);
                    $this->assertEqualsWithDelta($value, $points[$index], 1e-12);
                }
            }
        }
        $this->assertCount(11, $expected);
    }

    public function test_installation_costs_use_shared_commands_preview_rollback_and_safe_replay(): void
    {
        $actor = User::factory()->create();
        $tenant = $this->tenant($actor, 'dataset-cost-inputs');
        $manifest = $this->manifest();
        $manifest['installations'][0]['costs'] = ['values' => ['envcost' => '10 000,5', 'popdens' => 0, 'detsys' => '1', 'isosys' => '5', 'mitsys' => '2']];
        $manifest['installations'][0]['evidence'] = ['source_checks' => ['envcost' => true]];
        $service = app(DatasetImport::class);
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(5, $preview['counts']['installation_cost_fields']);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $model = Installation::findOrFail($manifest['installations'][0]['id']);
        $this->assertEquals(10000.5, $model->cost_values['envcost']);
        $this->assertSame(0, $model->cost_values['popdens']);
        $this->assertSame('2', $model->cost_values['mitsys']);
        $this->assertSame([], $model->cost_checks);
        $this->assertSame(2, $model->version);
        $input = app(InstallationCostInputValidation::class)->validated(['version' => $model->version, 'values' => ['envcost' => 15]]);
        app(InstallationCosts::class)->update($model->id, $input, $actor->id);
        $this->assertSame($report['batch_id'], $service->apply($manifest, $actor->id)['batch_id']);
        $this->assertEquals(15, $model->fresh()->cost_values['envcost']);
    }

    public function test_invalid_later_installation_cost_rolls_back_every_prior_business_write(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-cost-rollback');
        $manifest = $this->manifest();
        $manifest['installations'][0]['costs'] = ['values' => ['envcost' => 100]];
        $second = $manifest['installations'][0];
        $second['id'] = (string) Str::uuid();
        $second['input']['name'] = 'Вторая установка';
        $second['costs']['values']['envcost'] = -1;
        $manifest['installations'][] = $second;
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Invalid second installation must cancel the first.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('installations.1.costs.values.envcost', $exception->errors());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_management_import_preserves_unresolved_answers_and_details_and_never_replaces_later_edits(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-management');
        $manifest = $this->manifest();
        $management = app(InstallationManagement::class);
        $manifest['installations'][0]['costs'] = ['values' => ['popdens' => 0]];
        $manifest['installations'][0]['management'] = ['schema_version' => $management->schema()['version'],
            'details' => ['assessed_on' => '2026-10-20', 'auditor_name' => 'Bernard Austin', 'notes' => null],
            'answers' => [['question_id' => '1.1', 'state' => 'answered', 'value' => false, 'comment' => null], ['question_id' => '1.4', 'state' => 'unanswered', 'value' => null, 'comment' => null]]];
        $manifest['installations'][0]['evidence'] = ['source_verified' => true, 'source_not_applicable' => ['1.4' => true]];
        $service = app(DatasetImport::class);
        $this->assertSame(2, $service->preview($manifest, $actor->id)['counts']['management_answers']);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $id = $manifest['installations'][0]['id'];
        $saved = $management->read(Installation::findOrFail($id));
        $this->assertSame(3, $saved['version']);
        $this->assertSame('2026-10-20', $saved['details']['assessed_on']);
        $this->assertSame('Bernard Austin', $saved['details']['auditor_name']);
        $this->assertSame(false, $saved['answers'][0]['value']);
        $this->assertSame('unanswered', $saved['answers'][1]['state']);
        $this->assertFalse($saved['assessment']['ready']);
        $this->assertNull($saved['assessment']['fms_pof']);
        $patch = app(ManagementInputValidation::class)->validated(['version' => $saved['version'], 'schema_version' => $saved['schema_version'], 'answers' => [['question_id' => '1.1', 'state' => 'answered', 'value' => true, 'comment' => 'Уточнено после импорта']]]);
        $management->update($id, $patch, $actor->id, app(TenantWrites::class));
        $this->assertSame($report['batch_id'], $service->apply($manifest, $actor->id)['batch_id']);
        $current = $management->read(Installation::findOrFail($id));
        $this->assertTrue($current['answers'][0]['value']);
        $this->assertSame('Уточнено после импорта', $current['answers'][0]['comment']);
        $this->assertSame($saved['details'], $current['details']);
    }

    public function test_invalid_later_management_justification_rolls_back_costs_and_every_other_record(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-management-rollback');
        $manifest = $this->manifest();
        $manifest['installations'][0]['costs'] = ['values' => ['envcost' => 100]];
        $manifest['installations'][0]['management'] = ['schema_version' => app(InstallationManagement::class)->schema()['version'], 'answers' => [['question_id' => '1.1', 'state' => 'answered', 'value' => false, 'comment' => null]]];
        $second = $manifest['installations'][0];
        $second['id'] = (string) Str::uuid();
        $second['input']['name'] = 'Вторая установка';
        $second['management']['answers'][0] = ['question_id' => '1.1', 'state' => 'not_applicable', 'value' => null, 'comment' => null];
        $manifest['installations'][] = $second;
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Unjustified not-applicable answer must not become an accepted fact.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('installations.1.management.answers', $exception->errors());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_installation_origin_and_issues_are_owned_immutable_and_visible_without_approving_missing_answers(): void
    {
        $actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        $tenant = $this->tenant($actor, 'dataset-installation-origin');
        $manifest = $this->manifest();
        $manifest['installations'][0]['source_id'] = 'ks2';
        $schema = app(InstallationManagement::class)->schema()['version'];
        $manifest['installations'][0]['management'] = ['schema_version' => $schema, 'answers' => [['question_id' => '1.4', 'state' => 'unanswered', 'value' => null, 'comment' => null]]];
        $second = ['id' => (string) Str::uuid(), 'source_id' => 'other', 'input' => ['name' => 'Другая установка', 'parent_id' => $manifest['installations'][0]['input']['parent_id']]];
        $manifest['installations'][] = $second;
        $message = 'В old отмечено «не применимо», но обоснование отсутствует.';
        $manifest['issues'] = [
            ['installation_source_id' => 'ks2', 'source_path' => 'inst/ks2/ms/na/1.4', 'kind' => 'source_unknown', 'severity' => 'warning', 'source_value' => true, 'message' => $message],
            ['installation_source_id' => 'other', 'source_path' => 'inst/other/pass/area', 'kind' => 'source_unknown', 'severity' => 'warning', 'message' => 'Другая причина'],
        ];
        $service = app(DatasetImport::class);
        $service->preview($manifest, $actor->id);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $db = DB::connection('tenant');
        $this->assertSame(2, $db->table('dataset_import_installations')->count());
        $id = $manifest['installations'][0]['id'];
        $this->assertSame(2, $db->table('dataset_import_installations')->where('installation_id', $id)->value('imported_version'));
        try {
            $db->transaction(fn () => $db->table('dataset_import_installations')->where('installation_id', $id)->update(['imported_version' => 999]));
            $this->fail('Installation evidence must be immutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        try {
            $db->transaction(fn () => $db->table('dataset_import_issues')->insert(['id' => (string) Str::uuid(), 'batch_id' => $report['batch_id'], 'installation_id' => $id, 'equipment_id' => $manifest['equipment'][0]['id'], 'source_path' => 'invalid', 'kind' => 'source_unknown', 'severity' => 'warning', 'message' => 'Two owners']));
            $this->fail('A source issue must not have two owners.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
        $this->actingAs($actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $tenant->slug]);
        $url = '/api/installations/'.$id.'/management';
        $this->getJson($url)->assertOk()->assertJsonPath('data.data_origin.source_id', 'ks2')->assertJsonPath('data.data_origin.changed_since_import', false)
            ->assertJsonPath('data.data_origin.source_issue_count', 1)->assertJsonCount(1, 'data.source_issues')->assertJsonPath('data.source_issues.0.message', $message)
            ->assertJsonPath('data.source_issues_truncated', false)->assertJsonPath('data.assessment.ready', false);
        $this->getJson('/api/installations/'.$id.'/costs')->assertOk()->assertJsonPath('data.data_origin.source_id', 'ks2');
        $this->patchJson($url, ['version' => 2, 'schema_version' => $schema, 'answers' => [['question_id' => '1.4', 'state' => 'not_applicable', 'value' => null, 'comment' => 'Не входит в согласованный периметр']]])
            ->assertOk()->assertJsonPath('data.data_origin.changed_since_import', true)->assertJsonPath('data.source_issues.0.message', $message)->assertJsonPath('data.answers.0.state', 'not_applicable');
        $this->getJson('/api/installations/'.$second['id'].'/management')->assertOk()->assertJsonPath('data.data_origin.source_id', 'other')->assertJsonCount(1, 'data.source_issues')->assertJsonPath('data.source_issues.0.message', 'Другая причина');
    }

    public function test_installation_issue_read_is_bounded_and_reports_truncation(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-issue-bound');
        $manifest = $this->manifest();
        $id = $manifest['installations'][0]['id'];
        $manifest['issues'] = array_map(fn ($index) => ['installation_source_id' => $id, 'source_path' => 'inst/source/'.$index, 'kind' => 'source_unknown', 'severity' => 'warning', 'message' => 'Замечание '.$index], range(1, 201));
        app(DatasetImport::class)->apply($manifest, $actor->id);
        $data = app(InstallationImportData::class)->describe(Installation::findOrFail($id), true);
        $this->assertSame(201, $data['data_origin']['source_issue_count']);
        $this->assertCount(200, $data['source_issues']);
        $this->assertTrue($data['source_issues_truncated']);
    }

    public function test_readback_rejects_silent_point_loss_coordinate_shift_and_passport_drift_before_commit(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-readback');
        $manifest = $this->manifest();
        $db = DB::connection('tenant');
        $import = app(DatasetImport::class);
        foreach (['zero_to_null', 'missing_skip', 'shift', 'passport'] as $corruption) {
            foreach (['preview', 'apply'] as $method) {
                try {
                    $import->$method($manifest, $actor->id, false, function () use ($db, $corruption, $manifest) {
                        if ($corruption === 'passport') {
                            $db->table('equipment')->where('id', $manifest['equipment'][0]['id'])->update(['name' => 'Unexpected stored name']);
                        } elseif ($corruption === 'missing_skip') {
                            $db->table('equipment_thickness_points')->where('point_number', 2)->delete();
                        } elseif ($corruption === 'shift') {
                            $db->table('equipment_thickness_points')->where('point_number', 1)->update(['thickness_mm' => 0]);
                            $db->table('equipment_thickness_points')->where('point_number', 3)->update(['thickness_mm' => 12.123456789]);
                        } else {
                            $db->table('equipment_thickness_points')->where('point_number', 3)->update(['thickness_mm' => null]);
                        }
                    });
                    $this->fail('Readback or SQL ownership must reject lost canonical import values.');
                } catch (ValidationException $exception) {
                    $this->assertStringStartsWith('readback.equipment.EQ0001.', array_key_first($exception->errors()));
                } catch (QueryException $exception) {
                    $this->assertSame('missing_skip', $corruption);
                    $this->assertSame('23503', $exception->getCode());
                }
                $this->assertNoBusinessWrites();
            }
        }
        $report = $import->preview($manifest, $actor->id);
        $this->assertSame(3, $report['verification']['points']);
        $this->assertSame(1, $report['verification']['equipment']);
        $this->assertNoBusinessWrites();
    }

    public function test_preview_checks_deferred_sql_constraints_after_writing_the_full_receipt_graph(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-preview-constraints');
        $manifest = $this->manifest();
        $manifest['installations'][0]['costs'] = ['values' => ['envcost' => 1]];
        $db = DB::connection('tenant');
        try {
            app(DatasetImport::class)->preview($manifest, $actor->id, false, function () use ($db) {
                // This corruption is legal until deferred shape guards run; a rollback-only
                // preview that skips those guards would incorrectly report success.
                $db->table('installation_cost_annotations')->where('field_key', 'envcost')->update(['value_present' => false]);
            });
            $this->fail('Preview must validate deferred constraints, not merely roll back writes.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
            $this->assertStringContainsString('presence annotation', $exception->getMessage());
        }
        $this->assertNoBusinessWrites();
        // A subsequent valid preview must still work on the same connection and leave no receipt.
        $this->assertTrue(app(DatasetImport::class)->preview($manifest, $actor->id)['preview']);
        $this->assertNoBusinessWrites();
    }

    public function test_risk_criteria_import_preserves_explicit_scc_years_zero_disabled_thresholds_and_safe_replay(): void
    {
        $actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        $tenant = $this->tenant($actor, 'dataset-risk-criteria');
        $manifest = $this->manifest();
        $id = $manifest['installations'][0]['id'];
        $manifest['installations'][0]['maintenance'] = ['records' => [['id' => (string) Str::uuid(), 'kind' => 'mri', 'values' => ['effective_date' => '2026-01-01', 'interval_months' => 12]]]];
        $manifest['installations'][0]['risk_criteria'] = ['schema_version' => 'risk-targets-v1', 'values' => ['planScc' => 12, 'area' => null, 'pof' => 0, 'dThin' => 10]];
        $import = app(DatasetImport::class);
        $preview = $import->preview($manifest, $actor->id);
        $this->assertSame(4, $preview['counts']['risk_target_fields']);
        $this->assertSame(1, $preview['verification']['installations']);
        $this->assertNoBusinessWrites();
        $report = $import->apply($manifest, $actor->id);
        $this->actingAs($actor)->withHeaders(['X-Tenant' => $tenant->slug, 'Origin' => 'http://localhost:5173']);
        $url = '/api/installations/'.$id.'/risk-criteria';
        $this->getJson($url)->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.effective.planScc', 12)->assertJsonPath('data.origins.planScc', 'installation')->assertJsonPath('data.current_mri_months', 12)->assertJsonPath('data.effective.area', null)->assertJsonPath('data.effective.pof', 0)->assertJsonPath('data.data_origin.changed_since_import', false);
        $this->patchJson($url, ['schema_version' => 'risk-targets-v1', 'version' => 3, 'reset_fields' => ['planScc']])->assertOk()->assertJsonPath('data.effective.planScc', 1)->assertJsonPath('data.origins.planScc', 'mri')->assertJsonPath('data.data_origin.changed_since_import', true);
        app(TenantContext::class)->set($tenant);
        $this->assertTrue($import->apply($manifest, $actor->id)['replayed']);
        $current = app(InstallationRiskCriteria::class)->read(Installation::findOrFail($id));
        $this->assertSame(1, $current['effective']['planScc']);
        $this->assertSame($report['batch_id'], DB::connection('tenant')->table('dataset_import_installations')->where('installation_id', $id)->value('batch_id'));
    }

    public function test_maintenance_import_keeps_source_checks_separate_and_preserves_later_edits_and_owner_links(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0, 0));
        $actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        $tenant = $this->tenant($actor, 'dataset-maintenance');
        $manifest = $this->manifest();
        $id = $manifest['installations'][0]['id'];
        $manifest['installations'][0]['source_id'] = 'ks2';
        $manifest['installations'][0]['costs'] = ['values' => ['prodcost' => 1000000, 'popdens' => 0]];
        $manifest['installations'][0]['management'] = ['schema_version' => app(InstallationManagement::class)->schema()['version'], 'answers' => [['question_id' => '1.1', 'state' => 'answered', 'value' => false, 'comment' => null]]];
        [$latest,$initial,$repair] = array_map(fn () => (string) Str::uuid(), range(1, 3));
        $manifest['installations'][0]['maintenance'] = ['records' => [
            ['id' => $latest, 'kind' => 'mri', 'values' => ['effective_date' => '2026-10-12', 'interval_months' => 12], 'source_reference' => 'inst/ks2/mri/0', 'source_verified' => true],
            ['id' => $initial, 'kind' => 'mri', 'values' => ['effective_date' => '2009-12-31', 'interval_months' => 12], 'source_reference' => 'inst/ks2/mri/1', 'source_verified' => false],
            ['id' => $repair, 'kind' => 'repair', 'values' => ['starts_on' => '2026-07-05', 'ends_on' => '2026-07-12', 'repair_kind' => 'capital'], 'source_reference' => 'inst/ks2/rep/0', 'source_verified' => null],
        ]];
        $other = ['id' => (string) Str::uuid(), 'source_id' => 'other', 'input' => ['name' => 'Другая установка', 'parent_id' => $manifest['installations'][0]['input']['parent_id']]];
        $manifest['installations'][] = $other;
        $service = app(DatasetImport::class);
        $this->assertSame(3, $service->preview($manifest, $actor->id)['counts']['maintenance_records']);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $db = DB::connection('tenant');
        $this->assertSame(4, Installation::findOrFail($id)->version);
        $this->assertSame(3, $db->table('dataset_import_maintenance')->count());
        $this->assertSame(0, $db->table('installation_maintenance_records')->whereNotNull('verified_at')->count());
        $this->assertTrue($db->table('dataset_import_maintenance')->where('record_id', $latest)->value('source_verified'));
        $manual = (string) Str::uuid();
        $input = app(InstallationMaintenanceInputValidation::class)->validated(['version' => 1, 'upserts' => [['id' => $manual, 'kind' => 'mri', 'values' => []]]]);
        app(InstallationMaintenance::class)->update($other['id'], $input, $actor->id);
        foreach ([
            fn () => $db->table('dataset_import_maintenance')->where('record_id', $latest)->update(['source_verified' => false]),
            fn () => $db->table('dataset_import_maintenance')->insert(['record_id' => $manual, 'installation_id' => $id, 'kind' => 'mri', 'batch_id' => $report['batch_id'], 'source_reference' => 'wrong-owner', 'source_verified' => null, 'imported_version' => 1]),
        ] as $index => $write) {
            try {
                $db->transaction($write);
                $this->fail('Maintenance source must be immutable and match its record owner.');
            } catch (QueryException $exception) {
                $this->assertSame($index === 0 ? '23514' : '23503', $exception->getCode());
            }
        }
        $this->actingAs($actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $tenant->slug]);
        $url = '/api/installations/'.$id.'/maintenance';
        $this->getJson($url.'?kind=mri')->assertOk()->assertJsonPath('installation_version', 4)->assertJsonPath('data.0.values.effective_date', '2026-10-12')
            ->assertJsonPath('data.0.check', null)->assertJsonPath('data.0.data_origin.source_verified', true)->assertJsonPath('data.1.data_origin.source_verified', false)
            ->assertJsonPath('data.0.data_origin.changed_since_import', false);
        $this->getJson($url.'/summary')->assertOk()->assertJsonPath('data.planned_capital_on', '2027-07-12')->assertJsonPath('data.no_stops', null)
            ->assertJsonPath('data.downtime_complete', false)->assertJsonPath('data.production_loss', null);
        $this->getJson('/api/installations/'.$other['id'].'/maintenance?kind=mri')->assertOk()->assertJsonPath('data.0.data_origin', null);
        $this->patchJson($url, ['version' => 4, 'upserts' => [['id' => $latest, 'kind' => 'mri', 'values' => ['interval_months' => 24]]]])->assertOk();
        $this->getJson($url.'?kind=mri')->assertOk()->assertJsonPath('data.0.values.interval_months', 24)->assertJsonPath('data.0.data_origin.changed_since_import', true)->assertJsonPath('data.0.data_origin.source_verified', true);
        app(TenantContext::class)->set($tenant);
        $this->assertSame($report['batch_id'], $service->apply($manifest, $actor->id)['batch_id']);
        $this->assertSame(24, $db->table('installation_mri_parameters')->where('record_id', $latest)->value('interval_months'));
    }

    public function test_maintenance_source_cannot_supply_current_verification_and_invalid_later_record_rolls_back_everything(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-maintenance-invalid');
        $manifest = $this->manifest();
        $manifest['installations'][0]['costs'] = ['values' => ['prodcost' => 1000000]];
        $a = ['id' => (string) Str::uuid(), 'kind' => 'mri', 'values' => ['effective_date' => '2026-10-12', 'interval_months' => 12]];
        $manifest['installations'][0]['maintenance'] = ['records' => [$a + ['verified' => true]]];
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('A source verification must not become a current approval.');
        } catch (ValidationException) {
            $this->assertNoBusinessWrites();
        }
        $manifest['installations'][0]['maintenance']['records'] = [$a, ['id' => (string) Str::uuid(), 'kind' => 'repair', 'values' => ['starts_on' => '2026-07-12', 'ends_on' => '2026-07-05', 'repair_kind' => 'capital']]];
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('An invalid second record must cancel the first.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('installations.0.maintenance.records.1.values.ends_on', $exception->errors());
        }
        $this->assertNoBusinessWrites();
    }

    private function assertNoBusinessWrites(): void
    {
        foreach (['facility_nodes', 'installations', 'installation_risk_targets', 'installation_risk_target_overrides', 'installation_maintenance_records', 'installation_mri_parameters', 'installation_repair_parameters', 'installation_stop_parameters', 'installation_maintenance_presence', 'dataset_import_maintenance', 'installation_cost_parameters', 'installation_cost_annotations', 'installation_management_details', 'installation_management_answers', 'installation_management_selections', 'equipment', 'equipment_components', 'equipment_cmls', 'equipment_thickness_stages', 'equipment_thickness_values', 'equipment_thickness_points', 'dataset_import_batches', 'dataset_import_installations', 'dataset_import_equipment', 'dataset_import_flows', 'dataset_import_substances', 'process_flow_nodes', 'process_flow_composition', 'equipment_flow_selections', 'equipment_flow_contacts', 'equipment_flow_links', 'domain_events'] as $table) {
            $this->assertSame(0, DB::connection('tenant')->table($table)->count(), $table);
        }
    }

    public function test_substance_edits_roll_back_in_preview_feed_flow_calculations_and_replay_preserves_later_changes(): void
    {
        $actor = User::factory()->create()->refresh();
        $tenant = $this->tenant($actor, 'dataset-substances');
        $model = Substance::where('legacy_code', 'chem1')->firstOrFail();
        $before = $model->only(['version', 'overrides', 'sources', 'checks']);
        $manifest = $this->withFlows($this->manifest());
        $manifest['substances'] = [['code' => 'chem1', 'values' => ['mw' => '20,5', 'idlh' => 'не определено', 'inert' => false], 'evidence' => ['source_checks' => ['mw' => true]]]];
        $service = app(DatasetImport::class);
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(1, $preview['counts']['substances']);
        $this->assertEquals($before, $model->fresh()->only(array_keys($before)));
        $this->assertNoBusinessWrites();
        $service->apply($manifest, $actor->id);
        $model = $model->fresh();
        $this->assertSame(20.5, $model->overrides['mw']);
        $this->assertSame('не определено', $model->overrides['idlh']);
        $this->assertSame([], $model->checks);
        $flow = ProcessFlowNode::findOrFail($manifest['flow_nodes'][0]['id']);
        $input = app(FlowCompositionResolver::class)->resolve($flow);
        $result = app(MixtureCalculator::class)->calculate('mass', $input);
        $this->assertEquals(20.5, $result['properties']['molecular_weight']);
        app(CatalogRecords::class)->update(Substance::class, $model->id, ['version' => $model->version, 'values' => ['mw' => 21]], $actor->id, 'substance');
        $this->assertTrue($service->apply($manifest, $actor->id)['replayed']);
        $this->assertEquals(21, $model->fresh()->overrides['mw']);
        $actor->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->app['session']->flush();
        $this->actingAs($actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $tenant->slug]);
        $this->getJson('/api/substances/'.$model->id)->assertOk()->assertJsonPath('data.data_origin.source_id', 'chem1')->assertJsonPath('data.data_origin.changed_since_import', true)->assertJsonPath('data.values.idlh', 'не определено');
    }

    public function test_invalid_substance_and_existing_tenant_edits_never_leave_partial_imports(): void
    {
        $actor = User::factory()->create()->refresh();
        $this->tenant($actor, 'dataset-substance-failure');
        $model = Substance::where('legacy_code', 'chem1')->firstOrFail();
        $manifest = $this->manifest();
        $manifest['substances'] = [['code' => 'chem1', 'values' => ['mw' => 20]], ['code' => 'chem2', 'values' => ['mw' => -1]]];
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Invalid catalog data must roll back the dataset.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('substances.1.values.mw', $exception->errors());
        }
        $this->assertSame(1, $model->fresh()->version);
        $this->assertSame([], $model->fresh()->overrides);
        $this->assertNoBusinessWrites();
        app(CatalogRecords::class)->update(Substance::class, $model->id, ['version' => 1, 'values' => ['mw' => 22]], $actor->id, 'substance');
        $manifest['substances'] = [$manifest['substances'][0]];
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Existing tenant changes must not be overwritten.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertEquals(22, $model->fresh()->overrides['mw']);
        $this->assertSame(0, DB::connection('tenant')->table('facility_nodes')->count());
    }

    private function withFlows(array $manifest): array
    {
        $root = (string) Str::uuid();
        $leaf = (string) Str::uuid();
        $substance = DB::connection('tenant')->table('substances')->where('legacy_code', 'chem1')->value('id');
        $manifest['flow_nodes'] = [
            ['id' => $leaf, 'source_id' => 'ks2/ОП1.1-1', 'installation_id' => $manifest['installations'][0]['id'],
                'input' => ['name' => 'Сырой газ', 'code' => 'ОП1.1-1', 'kind' => 'flow', 'category' => 'main', 'parent_id' => $root],
                'card' => ['basis' => 'mass', 'attributes' => ['pressure' => 0, 'flow_rate_t_h' => 35, 'velocity_m_s' => 11.8],
                    'composition' => [['id' => (string) Str::uuid(), 'substance_id' => $substance, 'role' => 'main', 'amount' => 100]],
                    'screening' => ['values' => ['water' => false, 'co2' => true, 'ph' => 7.2]]]],
            ['id' => $root, 'source_id' => 'ks2/main', 'installation_id' => $manifest['installations'][0]['id'],
                'input' => ['name' => 'Основной процесс', 'kind' => 'folder', 'category' => 'main', 'parent_id' => null]],
        ];

        return $manifest;
    }

    public function test_flow_tree_cards_and_equipment_share_preview_atomicity_and_safe_replay(): void
    {
        $actor = User::factory()->create()->refresh();
        $this->tenant($actor, 'dataset-flows');
        $manifest = $this->withFlows($this->manifest());
        $service = app(DatasetImport::class);
        $manifest['equipment'][0]['flow_selection'] = [$manifest['flow_nodes'][0]['id']];
        $manifest['equipment'][0]['flow_contacts'] = [['id' => (string) Str::uuid(), 'scope' => 'component', 'component_id' => $manifest['equipment'][0]['components'][0]['id'], 'side' => 'internal', 'mode' => 'assign', 'verified' => false, 'flows' => [['id' => (string) Str::uuid(), 'flow_id' => $manifest['flow_nodes'][0]['id'], 'share_percent' => null]]]];
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(2, $preview['counts']['flow_nodes']);
        $this->assertSame(1, $preview['counts']['composition_lines']);
        $this->assertSame(1, $preview['counts']['flow_selections']);
        $this->assertSame(1, $preview['counts']['flow_contacts']);
        $this->assertSame(1, $preview['counts']['flow_links']);
        $this->assertNoBusinessWrites();
        $report = $service->apply($manifest, $actor->id);
        $flow = ProcessFlowNode::findOrFail($manifest['flow_nodes'][0]['id']);
        $this->assertSame('ОП1.1-1', $flow->code);
        $this->assertEquals(0, $flow->attributes['pressure']);
        $this->assertFalse($flow->screening['values']['water']);
        $this->assertSame(1, $flow->composition()->count());
        $db = DB::connection('tenant');
        $this->assertSame(2, $db->table('dataset_import_flows')->where('batch_id', $report['batch_id'])->count());
        app(ProcessFlows::class)->updateCard($flow->id, ['version' => $flow->version, 'attributes' => ['flow_rate_t_h' => 40]], $actor->id);
        $this->assertTrue($service->apply($manifest, $actor->id)['replayed']);
        $this->assertEquals(40, $flow->fresh()->attributes['flow_rate_t_h']);
    }

    public function test_invalid_flow_hierarchy_or_card_rolls_back_facilities_and_every_other_record(): void
    {
        $actor = User::factory()->create()->refresh();
        $this->tenant($actor, 'dataset-flow-failure');
        $manifest = $this->withFlows($this->manifest());
        $manifest['flow_nodes'][0]['card']['attributes']['velocity_m_s'] = -1;
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Invalid flow must roll back the dataset.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('flow_nodes.0.attributes.velocity_m_s', $exception->errors());
        }
        $this->assertNoBusinessWrites();
        $manifest = $this->withFlows($this->manifest());
        $manifest['flow_nodes'][1]['input']['parent_id'] = $manifest['flow_nodes'][0]['id'];
        try {
            app(DatasetImport::class)->preview($manifest, $actor->id);
            $this->fail('A cycle must be reported.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('flow_nodes', $exception->errors());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_flow_api_exposes_import_evidence_and_current_edits_without_rewriting_source(): void
    {
        $actor = User::factory()->create()->refresh();
        $actor->forceFill(['two_factor_confirmed_at' => now()])->save();
        $tenant = $this->tenant($actor, 'dataset-flow-api');
        $manifest = $this->withFlows($this->manifest());
        $manifest['source']['kind'] = 'demo_expanded';
        $manifest['issues'] = [['flow_source_id' => 'ks2/ОП1.1-1', 'source_path' => 'flowModels/ks2/ОП1.1-1/calc', 'kind' => 'source_unknown', 'severity' => 'warning', 'message' => 'Требуется сверка исходного расчёта.']];
        $report = app(DatasetImport::class)->apply($manifest, $actor->id);
        $this->assertSame(1, DB::connection('tenant')->table('dataset_import_issues')->whereNotNull('flow_id')->count());
        $this->app['session']->flush();
        $this->actingAs($actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $tenant->slug]);
        $url = '/api/process-flows/'.$manifest['flow_nodes'][0]['id'];
        $saved = $this->getJson($url)->assertOk()->assertJsonPath('data.data_origin.batch_id', $report['batch_id'])->assertJsonPath('data.data_origin.source_id', 'ks2/ОП1.1-1')->assertJsonPath('data.data_origin.source_issue_count', 1)->assertJsonPath('data.data_origin.changed_since_import', false)->json('data');
        $this->patchJson($url, ['version' => $saved['version'], 'attributes' => ['pressure' => 1]])->assertOk()->assertJsonPath('data.data_origin.changed_since_import', true);
        $this->getJson('/api/installations/'.$manifest['installations'][0]['id'].'/flow-nodes?q='.urlencode('ОП1.1-1'))->assertOk()->assertJsonPath('data.0.data_origin.kind', 'demo_expanded')->assertJsonPath('data.0.data_origin.changed_since_import', true);
    }

    public function test_generated_field_names_can_repeat_across_equipment_but_not_within_one_owner(): void
    {
        $manifest = $this->manifest();
        $manifest['source']['kind'] = 'demo_expanded';
        $manifest['equipment'][0]['generated_passport_fields'] = ['pressure_design_mpa'];
        $manifest['equipment'][0]['generated_scope_fields'] = [['kind' => 'tube', 'component_id' => null, 'fields' => ['pressure_design_mpa']]];
        $other = $manifest['equipment'][0];
        $other['id'] = (string) Str::uuid();
        $other['source_id'] = 'EQ0002';
        $other['inspections'][0]['requested_id'] = (string) Str::uuid();
        $manifest['equipment'][] = $other;
        $this->assertCount(2, app(DatasetManifest::class)->validate($manifest)['equipment']);
        $manifest['equipment'][1]['generated_passport_fields'][] = 'pressure_design_mpa';
        try {
            app(DatasetManifest::class)->validate($manifest);
            $this->fail('A local field list must remain unique.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('equipment.1.generated_passport_fields', $exception->errors());
        }
    }

    public function test_generated_values_keep_immutable_evidence_and_manual_changes_clear_current_markers(): void
    {
        $actor = User::factory()->create()->refresh();
        $this->tenant($actor, 'dataset-value-origins');
        $manifest = $this->manifest();
        $manifest['source']['kind'] = 'demo_expanded';
        $manifest['equipment'][0]['generated_passport_fields'] = ['pressure_design_mpa'];
        $manifest['equipment'][0]['create']['profile_code'] = 'ves_teplo';
        $manifest['equipment'][0]['components'][0]['nominal_thickness_mm'] = 14;
        $manifest['equipment'][0]['generated_component_fields'] = [['component_id' => $manifest['equipment'][0]['components'][0]['id'], 'field' => 'nominal_thickness_mm']];
        $manifest['equipment'][0]['thickness']['cmls'][0]['point_count'] = 3;
        $manifest['equipment'][0]['generated_cml_counts'] = [$manifest['equipment'][0]['thickness']['cmls'][0]['id']];
        $manifest['equipment'][0]['operating_scopes'] = [['kind' => 'tube', 'component_id' => null, 'values' => ['pressure_design_mpa' => 1], 'qualifiers' => []]];
        $manifest['equipment'][0]['generated_scope_fields'] = [['kind' => 'tube', 'component_id' => null, 'fields' => ['pressure_design_mpa']]];
        $manifest['equipment'][0]['thickness']['stages'][0]['measurements'][0]['point_origins'] = ['demo_generated', 'stored_snapshot', 'stored_snapshot'];
        $service = app(DatasetImport::class);
        $service->preview($manifest, $actor->id);
        $this->assertSame(0, DB::connection('tenant')->table('dataset_import_point_origins')->count());
        $service->apply($manifest, $actor->id);
        $db = DB::connection('tenant');
        $model = Equipment::with('components')->findOrFail($manifest['equipment'][0]['id']);
        app(EquipmentOrigins::class)->attach(collect([$model]));
        $this->assertSame(['pressure_design_mpa'], $model->import_origin['generated_passport_fields']);
        $this->assertSame([['id' => $manifest['equipment'][0]['components'][0]['id'], 'fields' => ['nominal_thickness_mm']]], $model->import_origin['generated_component_fields']);
        $this->assertSame($manifest['equipment'][0]['generated_cml_counts'], $model->import_origin['generated_cml_counts']);
        $this->assertSame(['pressure_design_mpa'], $model->import_origin['generated_scope_fields'][0]['fields']);
        $this->assertSame(['demo_generated', 'stored_snapshot', 'stored_snapshot'], json_decode($db->table('equipment_thickness_measurements')->value('point_origins'), true));
        $passport = $model->passport;
        $passport['pressure_design_mpa'] = 1;
        $cml = $manifest['equipment'][0]['thickness']['cmls'][0]['id'];
        $stage = $db->table('equipment_thickness_values')->value('stage_id');
        $model = app(EquipmentCommands::class)->update($model->id, ['installation_id' => $model->installation_id, 'passport' => $passport, 'version' => $model->version,
            'structure' => [array_replace($manifest['equipment'][0]['components'][0], ['nominal_thickness_mm' => 13])],
            'operating_scopes' => [['kind' => 'tube', 'component_id' => null, 'values' => ['pressure_design_mpa' => 2]]],
            'thickness' => ['stages' => [['id' => $stage, 'verified' => false, 'measured_on' => '2019-12-31', 'measurements' => [['cml_id' => $cml, 'points' => [11, null, 0]]]]]]], $actor->id);
        app(EquipmentOrigins::class)->attach(collect([$model]));
        $this->assertSame([], $model->import_origin['generated_passport_fields']);
        $this->assertSame([], $model->import_origin['generated_component_fields']);
        $this->assertSame([], $model->import_origin['generated_scope_fields']);
        $this->assertSame(['manual', 'stored_snapshot', 'stored_snapshot'], json_decode($db->table('equipment_thickness_measurements')->value('point_origins'), true));
        $this->assertEquals(12.123456789, $db->table('dataset_import_point_origins')->where('point_number', 1)->value('imported_thickness_mm'));
        try {
            $db->transaction(fn () => $db->table('dataset_import_point_origins')->update(['kind' => 'stored_snapshot']));
            $this->fail('Import evidence must be immutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->errorInfo[0]);
        }
    }

    public function test_invalid_generated_metadata_rolls_back_instead_of_labelling_stored_source_as_demo(): void
    {
        $actor = User::factory()->create()->refresh();
        $this->tenant($actor, 'dataset-invalid-origins');
        $manifest = $this->manifest();
        $manifest['equipment'][0]['generated_passport_fields'] = ['pressure_design_mpa'];
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Stored source must not contain generated input markers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('equipment.0.source', $exception->errors());
        }
        $this->assertNoBusinessWrites();
        $manifest['source']['kind'] = 'demo_expanded';
        $manifest['equipment'][0]['thickness']['stages'][0]['measurements'][0]['point_origins'] = ['demo_generated'];
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('Every point needs a matching origin.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('equipment.0.point_origins', $exception->errors());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_preview_uses_real_commands_and_rolls_back_every_business_write(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-preview');
        $report = app(DatasetImport::class)->preview($this->manifest(), $actor->id);
        $this->assertTrue($report['preview']);
        $this->assertNull($report['batch_id']);
        $this->assertSame(1, $report['counts']['equipment']);
        $this->assertSame(3, $report['counts']['points']);
        $this->assertSame(2, $report['counts']['inspections']);
        $this->assertNoBusinessWrites();
    }

    public function test_apply_is_atomic_and_replay_never_overwrites_subsequent_user_changes(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-apply');
        $manifest = $this->manifest();
        $service = app(DatasetImport::class);
        $report = $service->apply($manifest, $actor->id);
        $this->assertFalse($report['replayed']);
        $model = Equipment::findOrFail($manifest['equipment'][0]['id']);
        $this->assertSame('001', $model->passport['registration_number']);
        $this->assertEquals(0, $model->passport['pressure_design_mpa']);
        $db = DB::connection('tenant');
        $this->assertNull($db->table('equipment_cmls')->value('point_count'));
        $this->assertSame('CML-03.7', $db->table('equipment_cmls')->value('code'));
        $this->assertSame(0, $db->table('equipment_thickness_stages')->whereNotNull('verified_at')->count(), 'Source checks must not fabricate a current verifier.');
        $this->assertEquals([12.123456789, null, 0], json_decode($db->table('equipment_thickness_measurements')->value('points'), true));
        $passport = $model->passport;
        $passport['name'] = 'Изменено пользователем';
        app(EquipmentCommands::class)->update($model->id, ['installation_id' => $model->installation_id, 'passport' => $passport, 'version' => $model->version], $actor->id);
        $replay = $service->apply(array_reverse($manifest, true), $actor->id);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($report['batch_id'], $replay['batch_id']);
        $this->assertSame('Изменено пользователем', Equipment::findOrFail($model->id)->passport['name']);
        $this->assertSame(1, $db->table('dataset_import_batches')->count());
        try {
            $db->transaction(fn () => $db->table('dataset_import_batches')->where('id', $report['batch_id'])->update(['label' => 'Подмена источника']));
            $this->fail('Applied source evidence must be immutable.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }
    }

    public function test_failure_in_a_later_equipment_rolls_back_earlier_equipment_and_hierarchy(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-rollback');
        $manifest = $this->manifest();
        $bad = ['id' => (string) Str::uuid(), 'source_id' => 'EQ0002', 'create' => $manifest['equipment'][0]['create']];
        $bad['create']['passport']['pressure_design_mpa'] = -55;
        $manifest['equipment'][] = $bad;
        try {
            app(DatasetImport::class)->apply($manifest, $actor->id);
            $this->fail('The second equipment must fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('equipment.1.passport.pressure_design_mpa', $exception->errors());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_source_errors_are_visible_in_preview_and_need_explicit_apply_policy(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-source-errors');
        $manifest = $this->manifest();
        $manifest['issues'] = [['source_id' => 'EQ0001', 'source_path' => 'passport/pressure_operating_mpa', 'kind' => 'invalid_input', 'severity' => 'error', 'source_value' => '-55', 'message' => 'Некорректное давление источника сохранено для проверки.']];
        $service = app(DatasetImport::class);
        $preview = $service->preview($manifest, $actor->id);
        $this->assertSame(1, $preview['source_errors']);
        $this->assertNoBusinessWrites();
        try {
            $service->apply($manifest, $actor->id);
            $this->fail('Source errors require an explicit import policy.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('issues', $exception->errors());
        }
        $service->apply($manifest, $actor->id, true);
        $this->assertSame('-55', json_decode(DB::connection('tenant')->table('dataset_import_issues')->value('source_value'), true));
    }

    public function test_member_cannot_import(): void
    {
        $actor = User::factory()->create();
        $this->tenant($actor, 'dataset-member', 'member');
        try {
            app(DatasetImport::class)->preview($this->manifest(), $actor->id);
            $this->fail('A member must not import data.');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNoBusinessWrites();
    }

    public function test_equipment_api_exposes_import_origin_and_marks_later_changes_without_rewriting_evidence(): void
    {
        $actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        $tenant = $this->tenant($actor, 'dataset-origin-api');
        $manifest = $this->manifest();
        $manifest['source']['kind'] = 'demo_expanded';
        $report = app(DatasetImport::class)->apply($manifest, $actor->id);
        $id = $manifest['equipment'][0]['id'];
        $this->app['session']->flush();
        $this->actingAs($actor->refresh())->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $tenant->slug]);
        $saved = $this->getJson('/api/equipment/'.$id)->assertOk()->assertJsonPath('data.data_origin.batch_id', $report['batch_id'])->assertJsonPath('data.data_origin.kind', 'demo_expanded')->assertJsonPath('data.data_origin.changed_since_import', false)->json('data');
        $saved['passport']['name'] = 'После наполнения';
        $this->patchJson('/api/equipment/'.$id, ['installation_id' => $saved['installation_id'], 'passport' => $saved['passport'], 'version' => $saved['version']])->assertOk()->assertJsonPath('data.data_origin.changed_since_import', true);
        $this->getJson('/api/equipment')->assertOk()->assertJsonPath('data.0.data_origin.source_id', 'EQ0001')->assertJsonPath('data.0.data_origin.changed_since_import', true);
    }

    public function test_console_preview_requires_an_explicit_actor_and_restores_context(): void
    {
        $actor = User::factory()->create();
        $tenant = $this->tenant($actor, 'dataset-console');
        $directory = storage_path('framework/testing');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $path = $directory.'/dataset-'.Str::uuid().'.json';
        file_put_contents($path, json_encode($this->manifest(), JSON_THROW_ON_ERROR));
        try {
            $this->artisan('dataset:import', ['path' => $path, '--tenant' => $tenant->slug])->assertExitCode(1);
            $this->artisan('dataset:import', ['path' => $path, '--tenant' => $tenant->slug, '--actor' => (string) $actor->id])
                ->expectsOutput('Предпросмотр завершён; все предметные изменения отменены.')->assertExitCode(0);
            $this->assertSame($tenant->id, app(TenantContext::class)->id());
            $this->assertNoBusinessWrites();
        } finally {
            unlink($path);
        }
    }

    public function test_identical_source_ids_in_different_tenant_databases_do_not_share_data(): void
    {
        $actor = User::factory()->create();
        $first = $this->tenant($actor, 'dataset-first');
        $manifest = $this->manifest();
        app(DatasetImport::class)->apply($manifest, $actor->id);
        $second = $this->tenant($actor, 'dataset-second');
        app(DatasetImport::class)->apply($manifest, $actor->id);
        $equipment = Equipment::findOrFail($manifest['equipment'][0]['id']);
        $passport = $equipment->passport;
        $passport['name'] = 'Только вторая организация';
        app(EquipmentCommands::class)->update($equipment->id, ['installation_id' => $equipment->installation_id, 'passport' => $passport, 'version' => $equipment->version], $actor->id);
        app(TenantContext::class)->set($first);
        $this->assertSame('Сосуд', Equipment::findOrFail($equipment->id)->passport['name']);
        app(TenantContext::class)->set($second);
        $this->assertSame('Только вторая организация', Equipment::findOrFail($equipment->id)->passport['name']);
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
