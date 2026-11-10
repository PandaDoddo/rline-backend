<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Equipment\Models\Equipment;
use App\Modules\Rbi\Application\EquipmentThinningAssessment;
use App\Modules\Rbi\Models\ComponentRbiSettings;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComponentRbiSettingsTest extends TestCase
{
    use DatabaseMigrations;

    private ?Tenant $tenant = null;

    private User $actor;

    private string $equipment;

    private string $component;

    private function fixture(): void
    {
        $this->tenant = Tenant::create(['slug' => 'component-rbi', 'name' => 'Component RBI', 'auth_config' => ['local' => ['enabled' => true]]]);
        app(TenantDatabaseManager::class)->provision($this->tenant);
        $this->actor = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->actor->id, 'role' => 'owner', 'auth_provider' => 'local']);
        $this->actingAs($this->actor)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $this->tenant->slug]);
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['kind' => $kind, 'name' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $installation = $this->postJson('/api/installations', ['name' => 'Unit', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $card = $this->postJson('/api/equipment', ['installation_id' => $installation, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Example']])->assertCreated()->json('data');
        $this->equipment = $card['id'];
        $this->component = (string) Str::uuid();
        $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $installation, 'passport' => $card['passport'], 'version' => 1,
            'structure' => [['id' => $this->component, 'parent_id' => null, 'kind' => 'shell', 'name' => 'Корпус', 'is_coil' => false, 'status' => 'active', 'position' => 0]]])->assertOk();
        app(TenantContext::class)->set($this->tenant->refresh());
    }

    private function card(): array
    {
        return $this->getJson('/api/equipment/'.$this->equipment)->assertOk()->json('data');
    }

    private function save(array $rows, ?array $card = null, array $extra = [])
    {
        $card ??= $this->card();

        return $this->patchJson('/api/equipment/'.$this->equipment, ['installation_id' => $card['installation_id'], 'passport' => $card['passport'], 'version' => $card['version'], 'component_rbi' => $rows, ...$extra]);
    }

    public function test_sparse_settings_preserve_false_unknown_partial_updates_and_server_authors(): void
    {
        $this->fixture();
        $initial = $this->card();
        $this->assertSame([], $initial['component_rbi']);
        $saved = $this->save([['component_id' => $this->component, 'weld_efficiency' => '0,85', 'injection_point' => '0', 'notes' => ' 0 ']], $initial)->assertOk()->json('data');
        $settings = $saved['component_rbi'][0];
        $this->assertSame(0.85, $settings['weld_efficiency']);
        $this->assertFalse($settings['injection_point']);
        $this->assertNull($settings['dead_leg']);
        $this->assertNull($settings['confidence']);
        $this->assertSame('0', $settings['notes']);
        $this->assertSame($this->actor->id, $settings['updated_by']);
        $this->save([['component_id' => $this->component, 'notes' => 'Изменено']], $initial)->assertStatus(409);
        $partial = $this->save([['component_id' => $this->component, 'confidence' => 'high']], $saved)->assertOk()->json('data');
        $this->assertSame(0.85, $partial['component_rbi'][0]['weld_efficiency']);
        $this->assertFalse($partial['component_rbi'][0]['injection_point']);
        $this->assertSame('high', $partial['component_rbi'][0]['confidence']);
        $cleared = $this->save([['component_id' => $this->component, 'weld_efficiency' => null, 'injection_point' => null, 'confidence' => null, 'notes' => ' ']], $partial)->assertOk()->json('data');
        $this->assertSame([], $cleared['component_rbi']);
        app(TenantContext::class)->set($this->tenant->refresh());
        $this->assertSame(0, ComponentRbiSettings::count());
    }

    public function test_validation_and_ownership_fail_atomically_and_unknown_is_not_a_default(): void
    {
        $this->fixture();
        $card = $this->card();
        foreach ([['weld_efficiency' => 0], ['weld_efficiency' => 1.01], ['weld_efficiency' => true], ['confidence' => 'unknown'], ['injection_point' => 'yes'], ['notes' => "bad\0text"], ['updated_by' => 1], ['DF' => 10]] as $bad) {
            $this->save([['component_id' => $this->component, ...$bad]], $card)->assertUnprocessable();
        }
        $this->save([['component_id' => $this->component, 'dead_leg' => false], ['component_id' => (string) Str::uuid(), 'weld_efficiency' => 1]], $card)->assertUnprocessable();
        $this->assertSame([], $this->card()['component_rbi']);
        $this->assertSame($card['version'], $this->card()['version']);
        $this->save([['component_id' => $this->component, 'notes' => 'one'], ['component_id' => $this->component, 'notes' => 'two']], $card)->assertUnprocessable();
        $this->save([['component_id' => $this->component, 'weld_efficiency' => null]], $card)->assertOk()->assertJsonPath('data.component_rbi', []);
    }

    public function test_archived_settings_can_be_cleared_but_not_rewritten_and_members_cannot_write(): void
    {
        $this->fixture();
        $saved = $this->save([['component_id' => $this->component, 'dead_leg' => false]])->assertOk()->json('data');
        $row = $saved['components'][0];
        $archived = $this->save([], $saved, ['structure' => [['id' => $row['id'], 'parent_id' => null, 'kind' => $row['kind'], 'name' => $row['name'], 'is_coil' => false, 'status' => 'removed', 'position' => 0]]])->assertOk()->json('data');
        $this->save([['component_id' => $this->component, 'notes' => 'new']], $archived)->assertUnprocessable();
        $this->save([['component_id' => $this->component, 'dead_leg' => null]], $archived)->assertOk()->assertJsonPath('data.component_rbi', []);
        TenantMembership::where('tenant_id', $this->tenant->id)->where('user_id', $this->actor->id)->update(['role' => 'member']);
        $this->save([['component_id' => $this->component, 'confidence' => 'low']])->assertForbidden();
    }

    public function test_sql_rejects_invalid_coefficients_confidence_and_owner_reassignment(): void
    {
        $this->fixture();
        $this->save([['component_id' => $this->component, 'weld_efficiency' => 1]])->assertOk();
        app(TenantContext::class)->set($this->tenant->refresh());
        $db = DB::connection('tenant');
        foreach ([['weld_efficiency' => 0], ['weld_efficiency' => -1], ['weld_efficiency' => 'Infinity'], ['weld_efficiency' => 'NaN'], ['confidence' => 'bad'], ['notes' => ' '], ['equipment_id' => (string) Str::uuid()], ['component_id' => (string) Str::uuid()]] as $change) {
            try {
                $db->transaction(fn () => $db->table('equipment_component_rbi_settings')->where('component_id', $this->component)->update($change));
                $this->fail('Invalid direct SQL write must fail.');
            } catch (QueryException $error) {
                $this->assertContains($error->getCode(), ['23514', '23503'], $error->getMessage());
            }
        }
        $this->assertSame(1.0, ComponentRbiSettings::findOrFail($this->component)->weld_efficiency);
        $migration = require database_path('tenant-migrations/2026_11_10_000069_create_component_rbi_settings.php');
        DB::setDefaultConnection('tenant');
        try {
            $this->expectException(\RuntimeException::class);
            $migration->down();
        } finally {
            DB::setDefaultConnection('pgsql');
        }
    }

    public function test_source_assessment_reads_a_consistent_snapshot_without_creating_facts_or_accepting_a_run(): void
    {
        $this->fixture();
        $card = $this->save([['component_id' => $this->component, 'weld_efficiency' => 0.85, 'dead_leg' => false]])->assertOk()->json('data');
        app(TenantContext::class)->set($this->tenant->refresh());
        $db = DB::connection('tenant');
        $before = $db->table('domain_events')->count();
        $read = fn () => $db->transaction(function () use ($db) {
            $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return app(EquipmentThinningAssessment::class)->read(Equipment::findOrFail($this->equipment), '2026-11-10');
        });
        $result = $read();
        $this->assertSame($result, $read());
        $this->assertFalse($result['accepted_run']);
        $this->assertSame($card['version'], $result['equipment_version']);
        $this->assertCount(1, $result['elements']);
        $row = $result['elements'][0];
        $this->assertSame($this->component, $row['component_id']);
        $this->assertSame(0.85, $row['source']['component_rbi']['weld_efficiency']);
        $this->assertFalse($row['source']['component_rbi']['dead_leg']);
        $this->assertFalse($row['assessment']['ready']);
        $this->assertNull($row['assessment']['calculation']);
        $this->assertNotEmpty($row['assessment']['missing']);
        $this->assertSame($before, $db->table('domain_events')->count());
        $this->assertSame($card['version'], Equipment::findOrFail($this->equipment)->version);
    }

    public function test_segment_has_its_own_weld_coefficient_but_cannot_own_root_point_flags(): void
    {
        $this->fixture();
        $child = (string) Str::uuid();
        $card = $this->save([], null, ['structure' => [['id' => $child, 'parent_id' => $this->component, 'kind' => 'element', 'type_code' => 'shell_course', 'name' => 'Пояс', 'is_coil' => false, 'status' => 'active', 'position' => 0]]])->assertOk()->json('data');
        $this->save([['component_id' => $child, 'injection_point' => false]], $card)->assertUnprocessable();
        $saved = $this->save([['component_id' => $child, 'weld_efficiency' => 0.9], ['component_id' => $this->component, 'injection_point' => false]], $card)->assertOk()->json('data');
        $settings = array_column($saved['component_rbi'], null, 'component_id');
        $this->assertSame(0.9, $settings[$child]['weld_efficiency']);
        $this->assertNull($settings[$child]['injection_point']);
        $this->assertFalse($settings[$this->component]['injection_point']);
        app(TenantContext::class)->set($this->tenant->refresh());
        try {
            DB::connection('tenant')->transaction(fn () => DB::connection('tenant')->table('equipment_component_rbi_settings')->where('component_id', $child)->update(['dead_leg' => false]));
            $this->fail('SQL must reject a segment-owned point flag, including explicit false.');
        } catch (QueryException $error) {
            $this->assertSame('23514', $error->getCode());
        }
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
