<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Diagnostics\Application\EquipmentInspectionCategories;
use App\Modules\Diagnostics\Application\InspectionCategoryInputValidation;
use App\Modules\Equipment\Application\EquipmentCommands;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EquipmentInspectionCategoriesTest extends TestCase
{
    use DatabaseMigrations;

    private ?Tenant $tenant = null;

    private User $actor;

    private string $equipment;

    private string $component;

    private function fixture(): void
    {
        $this->tenant = Tenant::create(['slug' => 'inspection-facts', 'name' => 'Inspection facts', 'auth_config' => ['local' => ['enabled' => true]]]);
        app(TenantDatabaseManager::class)->provision($this->tenant);
        $this->actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->actor->id, 'role' => 'owner', 'auth_provider' => 'local']);
        $this->actingAs($this->actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $this->tenant->slug]);
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $installation = $this->postJson('/api/installations', ['name' => 'Example', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $record = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Example']])->assertCreated()->json('data');
        $this->equipment = $record['id'];
        $this->component = (string) Str::uuid();
        $inspection = $record['inspections'][0];
        $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $installation, 'passport' => ['name' => 'Example'], 'version' => 1,
            'structure' => [['id' => $this->component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0]],
            'inspections' => [['id' => $inspection['id'], 'kind' => $inspection['kind'], 'is_primary' => true, 'performed_on' => '2023-06-16'], ['id' => (string) Str::uuid(), 'kind' => 'ЭПБ', 'is_primary' => false, 'performed_on' => '2023-06-16']]])->assertOk();
        app(TenantContext::class)->set($this->tenant->refresh());
    }

    private function read(): array
    {
        return app(EquipmentInspectionCategories::class)->read(Equipment::findOrFail($this->equipment));
    }

    private function write(array $input): void
    {
        $input = app(InspectionCategoryInputValidation::class)->normalize(['schema_version' => 'inspection-categories-v1', ...$input]);
        $input = Validator::make(['inspection_categories' => $input], InspectionCategoryInputValidation::rules())->validate()['inspection_categories'];
        app(TenantWrites::class)->transaction(fn () => app(EquipmentInspectionCategories::class)->apply(Equipment::findOrFail($this->equipment), $input, $this->actor->id));
    }

    public function test_sparse_categories_keep_group_ownership_zero_defaults_and_unknown_cracks(): void
    {
        $this->fixture();
        $db = DB::connection('tenant');
        $calendar = $this->read();
        $this->assertCount(7, $calendar['stages']);
        $this->assertSame(0, $db->table('equipment_inspection_category_stages')->count());
        foreach ($calendar['stages'] as $stage) {
            $this->assertSame('ЭПБ', $stage['diagnostic_kind']);
            $this->assertSame([], $stage['observations']);
            $this->assertFalse($stage['persisted']);
        }
        $scc = collect($calendar['stages'])->firstWhere('group_code', 'scc');
        $hic = collect($calendar['stages'])->firstWhere('group_code', 'hic');
        $this->write(['stages' => [['id' => $scc['id'], 'group_code' => 'scc', 'inspection_id' => $scc['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'D', 'cracks_present' => false]]],
            ['id' => $hic['id'], 'group_code' => 'hic', 'inspection_id' => $hic['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'none']]]]]);
        $actual = collect($this->read()['stages'])->keyBy('id');
        $this->assertFalse($actual[$scc['id']]['observations'][0]['cracks_present']);
        $this->assertNull($actual[$hic['id']]['observations'][0]['cracks_present']);
        $this->assertSame('none', $actual[$hic['id']]['observations'][0]['category']);
        $this->write(['stages' => [['id' => $scc['id'], 'group_code' => 'scc', 'observations' => [['component_id' => $this->component, 'category' => null]]]]]);
        $row = $db->table('equipment_inspection_category_observations')->where('stage_id', $scc['id'])->first();
        $this->assertNull($row->category);
        $this->assertFalse($row->cracks_present);
        $this->write(['stages' => [['id' => $scc['id'], 'group_code' => 'scc', 'reset_components' => [$this->component]]]]);
        $this->assertSame(1, $db->table('equipment_inspection_category_observations')->count());
    }

    public function test_invalid_group_cracks_foreign_owner_and_duplicate_date_roll_back(): void
    {
        $this->fixture();
        $stage = collect($this->read()['stages'])->firstWhere('group_code', 'thin');
        foreach ([
            ['id' => $stage['id'], 'group_code' => 'thin', 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'B', 'cracks_present' => false]]],
            ['id' => $stage['id'], 'group_code' => 'thin', 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => (string) Str::uuid(), 'category' => 'B']]],
        ] as $entry) {
            try {
                $this->write(['stages' => [$entry]]);
                $this->fail('Invalid category must be rejected.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
            $this->assertSame(0, DB::connection('tenant')->table('equipment_inspection_category_stages')->count());
        }
        $manual = ['id' => (string) Str::uuid(), 'group_code' => 'scc', 'reference_date' => '2024-01-01'];
        $this->write(['stages' => [$manual]]);
        try {
            $this->write(['stages' => [[...$manual, 'id' => (string) Str::uuid()]]]);
            $this->fail('Duplicate date must be rejected.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
        $this->assertSame(1, DB::connection('tenant')->table('equipment_inspection_category_stages')->count());
        $this->write(['delete_stage_ids' => [$manual['id']]]);
        $this->assertSame(0, DB::connection('tenant')->table('equipment_inspection_category_stages')->count());
    }

    public function test_sql_rejects_identity_reassignment_incompatible_cracks_and_orphaned_calendar(): void
    {
        $this->fixture();
        $stage = collect($this->read()['stages'])->firstWhere('group_code', 'thin');
        $this->write(['stages' => [['id' => $stage['id'], 'group_code' => 'thin', 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'B']]]]]);
        $db = DB::connection('tenant');
        $observation = $db->table('equipment_inspection_category_observations')->first();
        $mutations = [
            fn () => $db->table('equipment_inspection_category_stages')->where('id', $stage['id'])->update(['group_code' => 'scc']),
            fn () => $db->table('equipment_inspection_category_observations')->where('id', $observation->id)->update(['component_id' => (string) Str::uuid()]),
            fn () => $db->table('equipment_inspection_category_observations')->where('id', $observation->id)->update(['category' => 'X']),
            fn () => $db->table('equipment_inspection_category_observations')->where('id', $observation->id)->update(['cracks_present' => false]),
            fn () => $db->table('equipment_inspections')->where('id', $stage['inspection_id'])->update(['performed_on' => null]),
        ];
        foreach ($mutations as $mutate) {
            try {
                $db->transaction(function () use ($db, $mutate) {
                    $mutate();
                    $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
                });
                $this->fail('Invalid SQL must fail.');
            } catch (QueryException $error) {
                $this->assertContains($error->getCode(), ['23514', '23503']);
            }
        }
        $this->assertSame('B', $db->table('equipment_inspection_category_observations')->value('category'));
        $this->assertSame('2023-06-16', $db->table('equipment_inspections')->where('id', $stage['inspection_id'])->value('performed_on'));
        $this->assertSame('thin', $db->table('equipment_inspection_category_stages')->value('group_code'));
    }

    public function test_calendar_validation_checks_the_final_dates_and_explicit_resets(): void
    {
        $this->fixture();
        $service = app(EquipmentInspectionCategories::class);
        $stage = collect($this->read()['stages'])->firstWhere('group_code', 'scc');
        $this->write(['stages' => [['id' => $stage['id'], 'group_code' => 'scc', 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'D']]]]]);
        $equipment = Equipment::findOrFail($this->equipment);
        $clear = [['id' => $stage['inspection_id'], 'performed_on' => null]];
        try {
            $service->guardDiagnostics($equipment, $clear);
            $this->fail('Stored categories cannot lose their performed date.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $service->guardDiagnostics($equipment, $clear, [$stage['id']]);
        $manual = ['id' => (string) Str::uuid(), 'group_code' => 'scc', 'reference_date' => '2024-01-01'];
        $this->write(['stages' => [$manual]]);
        try {
            $service->guardDiagnostics($equipment, [['id' => $stage['inspection_id'], 'performed_on' => '2024-01-01']]);
            $this->fail('Stored category stages cannot merge silently.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $service->guardDiagnostics($equipment, [['id' => $stage['inspection_id'], 'performed_on' => '2024-01-01']], [$manual['id']]);
        $this->assertSame(2, DB::connection('tenant')->table('equipment_inspection_category_stages')->count());
    }

    public function test_card_api_versions_audit_calendar_guard_and_member_permissions(): void
    {
        $this->fixture();
        $equipment = Equipment::findOrFail($this->equipment);
        $url = '/api/equipment/'.$equipment->id;
        $stage = collect($this->read()['stages'])->firstWhere('group_code', 'scc');
        $base = ['installation_id' => $equipment->installation_id, 'passport' => $equipment->passport, 'version' => 2];
        $input = ['schema_version' => 'inspection-categories-v1', 'stages' => [['id' => $stage['id'], 'group_code' => 'scc', 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'D', 'cracks_present' => false]]]]];
        $saved = $this->patchJson($url, $base + ['inspection_categories' => $input])->assertOk()->assertJsonPath('data.version', 3)->json('data.inspection_categories');
        $this->assertFalse(collect($saved['stages'])->firstWhere('id', $stage['id'])['observations'][0]['cracks_present']);
        $this->patchJson($url, $base + ['inspection_categories' => $input])->assertConflict();
        $base['version'] = 3;
        $clear = ['id' => $stage['inspection_id'], 'kind' => 'ЭПБ', 'is_primary' => false, 'performed_on' => null];
        $this->patchJson($url, $base + ['inspections' => [$clear]])->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonPath('data.version', 3);
        $this->patchJson($url, $base + ['inspections' => [$clear], 'inspection_categories' => ['schema_version' => 'inspection-categories-v1', 'delete_stage_ids' => [$stage['id']]]])->assertOk()->assertJsonPath('data.version', 4);
        app(TenantContext::class)->set($this->tenant);
        $event = DB::connection('tenant')->table('domain_events')->where('entity_type', 'equipment')->where('entity_id', $equipment->id)->orderByDesc('id')->first();
        $before = json_decode($event->before, true);
        $this->assertSame('D', collect($before['inspection_categories']['stages'])->firstWhere('id', $stage['id'])['observations'][0]['category']);
        $user = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'member', 'auth_provider' => 'local']);
        $this->app['session']->flush();
        $this->actingAs($user);
        $this->getJson($url)->assertOk();
        $base['version'] = 4;
        $this->patchJson($url, $base + ['inspection_categories' => $input])->assertForbidden();
    }

    public function test_each_group_uses_its_own_calendar_and_virtual_manual_dates_do_not_create_facts(): void
    {
        $this->fixture();
        $equipment = Equipment::findOrFail($this->equipment);
        $inspection = (string) Str::uuid();
        app(EquipmentCommands::class)->update($equipment->id, ['installation_id' => $equipment->installation_id, 'passport' => $equipment->passport, 'version' => $equipment->version,
            'inspections' => [['id' => $inspection, 'kind' => 'НО', 'is_primary' => false, 'performed_on' => '2024-01-01']],
            'thickness' => ['stages' => [['id' => (string) Str::uuid(), 'origin' => 'manual', 'measured_on' => '2024-02-01', 'verified' => false, 'measurements' => []]]],
            'visual_inspections' => ['schema_version' => 'visual-inspections-v1', 'stages' => [['id' => (string) Str::uuid(), 'reference_date' => '2024-03-01', 'measured_on' => '2024-03-01']]]], $this->actor->id);
        $read = $this->read();
        $dateGroups = fn ($date) => array_column(array_values(array_filter($read['stages'], fn ($stage) => $stage['source_date'] === $date)), 'group_code');
        $this->assertEqualsCanonicalizing(['ext', 'cui', 'cl'], $dateGroups('2024-01-01'));
        $this->assertSame(['thin'], $dateGroups('2024-02-01'));
        $this->assertEqualsCanonicalizing(['ext', 'cui', 'cl'], $dateGroups('2024-03-01'));
        $this->assertSame(0, DB::connection('tenant')->table('equipment_inspection_category_stages')->count());
        $stage = collect($read['stages'])->first(fn ($stage) => $stage['group_code'] === 'cl' && $stage['source_date'] === '2024-01-01');
        $this->write(['stages' => [['id' => $stage['id'], 'group_code' => 'cl', 'inspection_id' => $stage['inspection_id'], 'observations' => [['component_id' => $this->component, 'category' => 'C']]]]]);
        $manual = collect($read['stages'])->first(fn ($stage) => $stage['group_code'] === 'thin' && $stage['source_date'] === '2024-02-01');
        $this->write(['stages' => [['id' => $manual['id'], 'group_code' => 'thin', 'reference_date' => $manual['reference_date'], 'observations' => [['component_id' => $this->component, 'category' => 'C']]]]]);
        $this->assertSame(2, DB::connection('tenant')->table('equipment_inspection_category_stages')->count());
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        $central = DB::connection('pgsql');
        $this->assertSame('rline_test', $central->getDatabaseName());
        if ($this->tenant) {
            $name = 'rline_t_'.$this->tenant->id;
            $central->unprepared('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
            $central->unprepared('DROP ROLE IF EXISTS "'.$name.'"');
        }
        parent::tearDown();
    }
}
