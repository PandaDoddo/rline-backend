<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Equipment\Application\MechanismContextProvider;
use App\Modules\Equipment\Infrastructure\MechanismFacts;
use App\Modules\Equipment\Models\ComponentMechanismInputs;
use App\Modules\Equipment\Models\ComponentMechanismReview;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Equipment\Models\EquipmentMechanismInputs;
use App\Modules\Shared\Infrastructure\TenantWrites;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EquipmentMechanismStorageTest extends TestCase
{
    use DatabaseMigrations;

    private ?Tenant $tenant = null;

    private User $actor;

    private string $equipment;

    private string $component;

    private string $installation;

    private string $otherInstallation;

    private function fixture(): void
    {
        $this->tenant = Tenant::create(['slug' => 'mechanism-storage', 'name' => 'Mechanism storage', 'auth_config' => ['local' => ['enabled' => true]]]);
        app(TenantDatabaseManager::class)->provision($this->tenant);
        $this->actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->actor->id, 'role' => 'owner', 'auth_provider' => 'local']);
        $this->actingAs($this->actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $this->tenant->slug]);
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['kind' => $kind, 'name' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $this->installation = $this->postJson('/api/installations', ['name' => 'First', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $this->otherInstallation = $this->postJson('/api/installations', ['name' => 'Other', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $record = $this->postJson('/api/equipment', ['installation_id' => $this->installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Example']])->assertCreated()->json('data');
        $this->equipment = $record['id'];
        $this->component = (string) Str::uuid();
        $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $this->installation, 'passport' => ['name' => 'Example'], 'version' => 1, 'structure' => [['id' => $this->component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0]]])->assertOk();
        app(TenantContext::class)->set($this->tenant->refresh());
    }

    private function metadata(): array
    {
        return ['created_by' => $this->actor->id, 'updated_by' => $this->actor->id, 'created_at' => now(), 'updated_at' => now()];
    }

    private function rejected(callable $change, array $codes = ['23514', '23503']): void
    {
        $db = DB::connection('tenant');
        try {
            $db->transaction(function () use ($db, $change) {
                $change();
                $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('Invalid raw write must fail.');
        } catch (QueryException $error) {
            $this->assertContains($error->getCode(), $codes);
        }
    }

    public function test_typed_manual_inputs_keep_false_zero_unknowns_and_immutable_review_identity(): void
    {
        $this->fixture();
        $db = DB::connection('tenant');
        (new EquipmentMechanismInputs)->forceFill(['equipment_id' => $this->equipment, 'vibration_present' => false, 'hydrogen_partial_pressure_mpa' => 0, ...$this->metadata()])->save();
        $input = EquipmentMechanismInputs::findOrFail($this->equipment);
        $this->assertFalse($input->vibration_present);
        $this->assertSame(0.0, $input->hydrogen_partial_pressure_mpa);
        $this->assertNull($input->vibration_notes);
        (new ComponentMechanismInputs)->forceFill(['component_id' => $this->component, 'equipment_id' => $this->equipment, 'vibration_excluded' => false, ...$this->metadata()])->save();
        $this->assertFalse(ComponentMechanismInputs::findOrFail($this->component)->vibration_excluded);
        $this->assertNull(ComponentMechanismInputs::findOrFail($this->component)->tank_floor);
        $this->rejected(fn () => $db->table('equipment_mechanism_inputs')->where('equipment_id', $this->equipment)->update(['hydrogen_partial_pressure_mpa' => -1]));
        $this->rejected(fn () => $db->table('equipment_component_mechanism_inputs')->where('component_id', $this->component)->update(['component_id' => (string) Str::uuid()]));
        $db->table('equipment_mechanism_reviews')->insert(['component_id' => $this->component, 'equipment_id' => $this->equipment, 'input_signature' => str_repeat('a', 64), 'verified_by' => $this->actor->id, 'verified_at' => now()]);
        $this->rejected(fn () => $db->table('equipment_mechanism_reviews')->where('component_id', $this->component)->update(['input_signature' => str_repeat('b', 64)]));
        $this->assertSame($this->actor->id, ComponentMechanismReview::findOrFail($this->component)->verified_by);
        $migration = require database_path('tenant-migrations/2026_11_10_000067_create_equipment_mechanism_inputs.php');
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        try {
            $migration->down();
            $this->fail('Populated mechanism inputs cannot be dropped.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Cannot discard', $error->getMessage());
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    public function test_question_notes_keep_side_and_flow_coordinates_and_reject_cross_installation_ownership(): void
    {
        $this->fixture();
        $db = DB::connection('tenant');
        $create = function ($installation, $category) {
            $version = $this->getJson('/api/installations/'.$installation.'/flow-nodes')->json('structure_version');

            return $this->postJson('/api/installations/'.$installation.'/flow-nodes', ['structure_version' => $version, 'kind' => 'flow', 'category' => $category, 'name' => 'Flow'])->assertCreated()->json('data.id');
        };
        $own = $create($this->installation, 'main');
        $foreign = $create($this->otherInstallation, 'auxiliary');
        app(TenantContext::class)->set($this->tenant);
        $base = ['equipment_id' => $this->equipment, 'component_id' => $this->component, 'question_id' => 'hcl|1', 'side' => 'internal', 'flow_id' => null, 'note' => 'Уточнить источник', ...$this->metadata()];
        $db->table('equipment_mechanism_question_notes')->insert(['id' => (string) Str::uuid(), ...$base]);
        $this->rejected(fn () => $db->table('equipment_mechanism_question_notes')->insert(['id' => (string) Str::uuid(), ...$base]), ['23505']);
        $db->table('equipment_mechanism_question_notes')->insert(['id' => (string) Str::uuid(), ...$base, 'flow_id' => $own]);
        $db->table('equipment_mechanism_question_notes')->insert(['id' => (string) Str::uuid(), ...$base, 'side' => 'external', 'flow_id' => $own]);
        $this->assertSame(3, $db->table('equipment_mechanism_question_notes')->count());
        $this->rejected(fn () => $db->table('equipment_mechanism_question_notes')->insert(['id' => (string) Str::uuid(), ...$base, 'flow_id' => $foreign]));
        $this->rejected(fn () => $db->table('equipment')->where('id', $this->equipment)->update(['installation_id' => $this->otherInstallation]));
        $this->rejected(fn () => $db->table('process_flow_nodes')->where('id', $own)->update(['installation_id' => $this->otherInstallation]));
        $override = ['id' => (string) Str::uuid(), 'equipment_id' => $this->equipment, 'component_id' => $this->component, 'mechanism_code' => 'hcl', 'side' => 'internal', 'state' => 'active', 'reason' => 'По результатам обследования', 'source_signature' => str_repeat('a', 64), ...$this->metadata()];
        $db->table('equipment_mechanism_overrides')->insert($override);
        $this->rejected(fn () => $db->table('equipment_mechanism_overrides')->where('id', $override['id'])->update(['reason' => ' ']));
        $this->rejected(fn () => $db->table('equipment_mechanism_overrides')->where('id', $override['id'])->update(['side' => 'external']));
        $this->rejected(fn () => $db->table('equipment_mechanism_overrides')->insert([...$override, 'id' => (string) Str::uuid(), 'mechanism_code' => 'extf', 'side' => 'internal']));
    }

    public function test_fact_repository_keeps_sparse_inputs_and_context_sources_are_read_in_batches(): void
    {
        $this->fixture();
        $db = DB::connection('tenant');
        $facts = app(MechanismFacts::class);
        app(TenantWrites::class)->transaction(function () use ($facts) {
            $facts->root($this->equipment, ['vibration_present' => false, 'hydrogen_partial_pressure_mpa' => 0], $this->actor->id);
            $facts->component($this->equipment, $this->component, ['tank_floor' => false], $this->actor->id);
            $facts->note($this->equipment, ['component_id' => $this->component, 'question_id' => 'mfat|2', 'side' => 'through', 'flow_id' => null, 'note' => 'Вибрация не выявлена'], $this->actor->id);
        });
        $read = $facts->read($this->equipment);
        $this->assertFalse($read['inputs']['vibration_present']);
        $this->assertSame(0.0, $read['inputs']['hydrogen_partial_pressure_mpa']);
        $this->assertNull($read['inputs']['vibration_notes']);
        $this->assertSame('through', $read['question_notes'][0]['side']);
        $name = $db->table('steel_grades')->where('status', 'active')->value('label');
        $this->assertNotNull($name);
        $db->table('equipment_components')->where('id', $this->component)->update(['material' => $name]);
        $version = $this->getJson('/api/installations/'.$this->installation.'/flow-nodes')->json('structure_version');
        $flow = $this->postJson('/api/installations/'.$this->installation.'/flow-nodes', ['structure_version' => $version, 'kind' => 'flow', 'category' => 'main', 'name' => 'Flow'])->assertCreated()->json('data');
        app(TenantContext::class)->set($this->tenant);
        $db = DB::connection('tenant');
        $equipment = Equipment::findOrFail($this->equipment)->load('components');
        $flows = ['contacts' => [['id' => 'contact', 'flows' => [['flow_id' => $flow['id'], 'accepted_version' => $flow['version']]]]], 'effective' => [['component_id' => $this->component, 'side' => 'internal', 'contact_id' => 'contact', 'mode' => 'assign']]];
        $visual = ['elements' => [['component_id' => $this->component, 'insulated' => false, 'insulation_requires_review' => false, 'insulation_source' => 'manual']]];
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $context = $db->transaction(function () use ($equipment, $flows, $visual, $read) {
            $factory = app(MechanismContextProvider::class)->factory($equipment, $flows, [], $visual, $read, true);
            $row = null;
            for ($i = 0; $i < 100; $i++) {
                $row = $factory->element($this->component, false);
            }

            return $row;
        });
        $this->assertTrue($context['material_ref']['resolved']);
        $this->assertTrue($context['internal'][0]['usable']);
        $this->assertSame(1, count(array_filter($queries, fn ($sql) => str_contains($sql, 'steel_grades'))));
        $this->assertSame(1, count(array_filter($queries, fn ($sql) => str_contains($sql, 'process_flow_nodes'))));
    }

    private function currentCard(): array
    {
        return $this->getJson('/api/equipment/'.$this->equipment)->assertOk()->json('data');
    }

    private function saveMechanisms(array $input, ?array $card = null)
    {
        $card ??= $this->currentCard();

        return $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $card['installation_id'], 'passport' => $card['passport'], 'version' => $card['version'],
            'mechanisms' => ['schema_version' => 'component-screening-v1', ...$input]]);
    }

    public function test_api_keeps_false_zero_comments_versions_and_review_authorship(): void
    {
        $this->fixture();
        $initial = $this->currentCard();
        $saved = $this->saveMechanisms(['inputs' => ['vibration_present' => '0', 'hydrogen_partial_pressure_mpa' => '0,0', 'hydrogen_notes' => '  Явный ноль  '],
            'component_inputs' => [['component_id' => $this->component, 'tank_floor' => false]],
            'question_notes' => [['component_id' => $this->component, 'question_id' => 'mfat|2', 'side' => 'through', 'flow_id' => null, 'note' => 'По результатам осмотра']]], $initial)->assertOk()->json('data');
        $this->assertFalse($saved['mechanisms']['inputs']['vibration_present']);
        $this->assertSame(0, $saved['mechanisms']['inputs']['hydrogen_partial_pressure_mpa']);
        $this->assertSame('Явный ноль', $saved['mechanisms']['inputs']['hydrogen_notes']);
        $this->assertSame('По результатам осмотра', $saved['mechanisms']['question_notes'][0]['note']);
        $detail = $this->getJson('/api/equipment/'.$this->equipment.'/components/'.$this->component.'/mechanisms')->assertOk()->json('data');
        $this->assertSame($saved['mechanisms']['elements'][0]['input_signature'], $detail['input_signature']);
        $methods = array_column($detail['mechanisms'], null, 'id');
        $this->assertNotEmpty($methods['mfat']['sides'][0]['runs'][0]['questions']);
        $this->getJson('/api/equipment/'.$this->equipment.'/components/'.Str::uuid().'/mechanisms')->assertNotFound();
        $this->saveMechanisms(['inputs' => ['vibration_present' => true]], $initial)->assertStatus(409);
        $element = array_column($saved['mechanisms']['elements'], null, 'component_id')[$this->component];
        $this->assertTrue($element['active']);
        $review = ['component_id' => $this->component, 'verified' => true, 'input_signature' => $element['input_signature']];
        $checked = $this->saveMechanisms(['reviews' => [$review]], $saved)->assertOk()->json('data');
        $checkedElement = array_column($checked['mechanisms']['elements'], null, 'component_id')[$this->component];
        $this->assertTrue($checkedElement['review']['verified']);
        $this->assertSame($this->actor->id, $checkedElement['review']['verified_by']);
        $again = $this->saveMechanisms(['reviews' => [$review]], $checked)->assertOk()->json('data');
        $this->assertSame($checkedElement['review'], array_column($again['mechanisms']['elements'], null, 'component_id')[$this->component]['review']);
        $this->saveMechanisms(['inputs' => ['vibration_notes' => 'Уточнено после проверки']], $again)->assertUnprocessable();
        $changed = $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $again['installation_id'], 'passport' => [...$again['passport'], 'temperature_max_c' => 100], 'version' => $again['version']])->assertOk()->json('data');
        $changedElement = array_column($changed['mechanisms']['elements'], null, 'component_id')[$this->component];
        $this->assertFalse($changedElement['review']['verified']);
        $this->assertTrue($changedElement['review']['outdated']);
        $this->saveMechanisms(['reviews' => [$review]], $changed)->assertStatus(409);
        $this->saveMechanisms(['reviews' => [['component_id' => $this->component, 'verified' => false]]], $changed)->assertOk()->assertJsonPath('data.mechanisms.reviews', []);
    }

    public function test_api_rejects_stale_overrides_bad_coordinates_duplicates_and_foreign_owners_atomically(): void
    {
        $this->fixture();
        $card = $this->currentCard();
        $this->saveMechanisms(['inputs' => 'malformed'], $card)->assertUnprocessable();
        $this->saveMechanisms(['inputs' => ['vibration_notes' => "Invalid\0comment"]], $card)->assertUnprocessable();
        $element = array_column($card['mechanisms']['elements'], null, 'component_id')[$this->component];
        $override = ['component_id' => $this->component, 'mechanism_code' => 'hcl', 'side' => 'internal', 'state' => 'active', 'reason' => 'Подтверждено обследованием', 'source_signature' => $element['source_signature']];
        $this->saveMechanisms(['overrides' => [[...$override, 'source_signature' => str_repeat('f', 64)]]], $card)->assertStatus(409);
        $this->saveMechanisms(['overrides' => [[...$override, 'reason' => ' ']]], $card)->assertUnprocessable()->assertJsonValidationErrors('mechanisms.overrides.0.reason');
        $this->saveMechanisms(['inputs' => ['vibration_present' => true], 'overrides' => [$override, $override]], $card)->assertUnprocessable();
        $this->saveMechanisms(['overrides' => [[...$override, 'side' => 'external']]], $card)->assertUnprocessable()->assertJsonValidationErrors('mechanisms.overrides.0.side');
        $this->saveMechanisms(['component_inputs' => [['component_id' => (string) Str::uuid(), 'tank_floor' => true]]], $card)->assertUnprocessable();
        $this->saveMechanisms(['question_notes' => [['component_id' => $this->component, 'question_id' => 'hcl|1', 'side' => 'through', 'flow_id' => null, 'note' => 'Неверная сторона']]], $card)->assertUnprocessable();
        $this->assertSame($card['version'], $this->currentCard()['version']);
        $this->assertNull($this->currentCard()['mechanisms']['inputs']['vibration_present']);
        $saved = $this->saveMechanisms(['overrides' => [$override]], $card)->assertOk()->json('data');
        $this->assertFalse($saved['mechanisms']['overrides'][0]['stale']);
        $methods = array_column($saved['mechanisms']['elements'][0]['mechanisms'], null, 'id');
        $this->assertSame('active', $methods['hcl']['state']);
        $review = ['component_id' => $this->component, 'verified' => true, 'input_signature' => $saved['mechanisms']['elements'][0]['input_signature']];
        $checked = $this->saveMechanisms(['reviews' => [$review]], $saved)->assertOk()->json('data');
        $this->saveMechanisms(['overrides' => [[...$override, 'state' => null]]], $checked)->assertUnprocessable();
        $this->saveMechanisms(['reviews' => [['component_id' => $this->component, 'verified' => false]], 'overrides' => [[...$override, 'state' => null]]], $checked)->assertOk()->assertJsonPath('data.mechanisms.overrides', []);
        TenantMembership::where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
        $this->saveMechanisms(['inputs' => ['vibration_present' => true]])->assertForbidden();
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
