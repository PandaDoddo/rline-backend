<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\DataExchange\Application\DatasetImport;
use App\Modules\DataExchange\Application\DatasetManifest;
use App\Modules\DataExchange\Application\EquipmentOrigins;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Models\Equipment;
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

    private function assertNoBusinessWrites(): void
    {
        foreach (['facility_nodes', 'installations', 'equipment', 'equipment_components', 'equipment_cmls', 'equipment_thickness_stages', 'equipment_thickness_values', 'equipment_thickness_points', 'dataset_import_batches', 'dataset_import_equipment', 'domain_events'] as $table) {
            $this->assertSame(0, DB::connection('tenant')->table($table)->count(), $table);
        }
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
        $actor = User::factory()->create(['two_factor_confirmed_at' => now()]);
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
