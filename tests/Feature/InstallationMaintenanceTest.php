<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Installations\Application\InstallationMaintenance;
use App\Modules\Installations\Application\InstallationMaintenanceInputValidation;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InstallationMaintenanceTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function login(string $slug, string $role = 'owner'): void
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
    }

    private function installation(): string
    {
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }

        return $this->postJson('/api/installations', ['name' => 'Установка ремонтов', 'parent_id' => $parent])->assertCreated()->json('data.id');
    }

    private function entry(string $kind, array $values = [], bool $verified = false): array
    {
        return ['id' => (string) Str::uuid(), 'kind' => $kind, 'values' => $values, 'verified' => $verified];
    }

    public function test_summary_calendar_interval_union_of_stops_and_missing_cost_are_explicit(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->login('maintenance-summary');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $this->getJson($url.'/summary')->assertOk()->assertJsonPath('data.current_mri_months', null)->assertJsonPath('data.planned_capital_on', null)->assertJsonPath('data.production_loss', null);
        $entries = [
            $this->entry('mri', ['effective_date' => '2025-01-01', 'interval_months' => 12]),
            $this->entry('mri', ['effective_date' => '2026-01-01', 'interval_months' => 1]),
            $this->entry('repair', ['starts_on' => '2026-01-20', 'ends_on' => '2026-01-31', 'repair_kind' => 'capital']),
            $this->entry('repair', ['starts_on' => '2027-01-01', 'ends_on' => '2027-02-01', 'repair_kind' => 'capital']),
            $this->entry('stop', ['starts_at' => '2026-11-01T00:00:00+05:00', 'ends_at' => '2026-11-01T02:00:00+05:00']),
            $this->entry('stop', ['starts_at' => '2026-11-01T01:00:00+05:00', 'ends_at' => '2026-11-01T03:00:00+05:00']),
            $this->entry('stop', ['starts_at' => '2026-11-02T01:00:00Z']),
        ];
        $this->patchJson($url, ['version' => 1, 'upserts' => $entries])->assertOk()->assertJsonPath('version', 2);
        $summary = $this->getJson($url.'/summary')->assertOk()->assertJsonPath('data.current_mri_months', 1)->assertJsonPath('data.last_capital_ended_on', '2026-01-31')->assertJsonPath('data.planned_capital_on', '2026-02-28')->assertJsonPath('data.downtime_hours', 3)->assertJsonPath('data.incomplete_stops', 1)->assertJsonPath('data.production_loss', null)->json('data');
        $this->assertNotEmpty($summary['warnings']);
        $this->patchJson('/api/installations/'.$id.'/costs', ['version' => 2, 'values' => ['prodcost' => 2400]])->assertOk();
        $this->getJson($url.'/summary')->assertJsonPath('data.production_loss', null)->assertJsonPath('data.known_production_loss', 300)->assertJsonPath('data.downtime_complete', false);
        $list = $this->getJson($url.'?kind=stop')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('installation_version', 3)->json('data');
        $this->assertContains('2026-10-31T19:00:00.000000Z', array_column(array_column($list, 'values'), 'starts_at'));
        $this->patchJson($url, ['version' => 3, 'no_stops' => true])->assertUnprocessable();
        $second = $this->installation();
        $this->getJson('/api/installations/'.$second.'/maintenance/summary')->assertJsonPath('data.incomplete_stops', 0)->assertJsonPath('data.downtime_hours', 0);
    }

    public function test_verified_rows_atomic_conflicts_ownership_deletion_and_history(): void
    {
        $this->login('maintenance-locks');
        $id = $this->installation();
        $other = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $mri = $this->entry('mri', ['effective_date' => '2026-01-01', 'interval_months' => 48], true);
        $this->patchJson($url, ['version' => 1, 'upserts' => [$mri]])->assertOk();
        $this->patchJson($url, ['version' => 1, 'no_stops' => true])->assertConflict();
        $this->patchJson($url, ['version' => 2, 'upserts' => [['id' => $mri['id'], 'kind' => 'mri', 'values' => ['interval_months' => 24]]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'delete_ids' => [$mri['id']]])->assertUnprocessable();
        $this->patchJson('/api/installations/'.$other.'/maintenance', ['version' => 1, 'upserts' => [$mri]])->assertNotFound();
        $this->patchJson($url, ['version' => 2, 'upserts' => [['id' => $mri['id'], 'kind' => 'mri', 'unlock' => true, 'verified' => true, 'values' => ['interval_months' => 24, 'reason' => 'Пересмотр']]]])->assertOk();
        $this->getJson($url.'?kind=mri')->assertJsonPath('data.0.values.interval_months', 24)->assertJsonPath('data.0.complete', true);
        $this->patchJson($url, ['version' => 3, 'upserts' => [['id' => $mri['id'], 'kind' => 'mri', 'verified' => false]]])->assertOk();
        $this->patchJson($url, ['version' => 4, 'delete_ids' => [$mri['id']], 'no_stops' => true])->assertOk();
        $this->getJson($url.'?kind=mri')->assertJsonPath('meta.total', 0);
        $this->patchJson($url, ['version' => 5, 'upserts' => [$mri]])->assertNotFound();
        $this->getJson($url.'/events')->assertOk()->assertJsonPath('meta.total', 4)->assertJsonPath('data.0.changes.0.to', 'удалена');
        $this->login('maintenance-locks', 'member');
        $this->getJson($url.'/summary')->assertOk();
        $this->patchJson($url, ['version' => 5, 'no_stops' => false])->assertForbidden();
        $this->login('maintenance-private');
        $this->getJson($url.'?kind=mri')->assertNotFound();
    }

    public function test_sparse_incomplete_rows_validation_and_transaction_rollback(): void
    {
        $this->login('maintenance-validation');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $partial = $this->entry('repair', ['starts_on' => '2026-03-01']);
        $this->patchJson($url, ['version' => 1, 'upserts' => [$partial]])->assertOk();
        $this->getJson($url.'?kind=repair')->assertOk()->assertJsonPath('data.0.complete', false)->assertJsonPath('data.0.values.ends_on', null);
        $this->patchJson($url, ['version' => 2, 'upserts' => [['id' => $partial['id'], 'kind' => 'repair', 'values' => ['ends_on' => '2026-02-28']]]])->assertUnprocessable();
        foreach ([['starts_at' => '2026-03-01T12:00'], ['effective_date' => '2026-02-30'], ['interval_months' => 0], ['reason' => str_repeat('x', 5001)]] as $values) {
            $this->patchJson($url, ['version' => 2, 'upserts' => [$this->entry('mri', $values)]])->assertUnprocessable();
        }
        $this->patchJson($url, ['version' => 2, 'upserts' => [$this->entry('stop', [], true)]])->assertUnprocessable();
        $good = $this->entry('mri', ['effective_date' => '2026-01-01', 'interval_months' => 48]);
        $bad = $this->entry('stop', ['starts_at' => '2026-02-01T12:00:00Z', 'ends_at' => '2026-01-01T12:00:00Z']);
        $this->patchJson($url, ['version' => 2, 'upserts' => [$good, $bad]])->assertUnprocessable();
        $this->getJson($url.'?kind=mri')->assertJsonPath('meta.total', 0)->assertJsonPath('installation_version', 2);
        $this->patchJson($url, ['version' => 2, 'upserts' => [$good, $this->entry('mri', $good['values'])]])->assertUnprocessable();
        $this->getJson($url.'?kind=mri')->assertJsonPath('meta.total', 0);
    }

    public function test_pagination_and_latest_performed_inspection_counts_do_not_expand_history(): void
    {
        $this->login('maintenance-pages');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $entries = [];
        for ($i = 0; $i < 65; $i++) {
            $entries[] = $this->entry('mri', ['effective_date' => sprintf('%04d-%02d-01', 2020 + intdiv($i, 12), $i % 12 + 1), 'interval_months' => 48]);
        }
        $entries[] = $this->entry('repair', ['starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'repair_kind' => 'medium']);
        $this->patchJson($url, ['version' => 1, 'upserts' => $entries])->assertOk();
        $this->getJson($url.'?kind=mri&per_page=50')->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 65);
        $this->getJson($url.'?kind=mri&per_page=50&page=2')->assertOk()->assertJsonCount(15, 'data');
        $profile = array_key_first(array_filter(config('equipment.profiles'), fn ($profile) => $profile['g'] === 'ves'));
        $equipment = $this->postJson('/api/equipment', ['installation_id' => $id, 'profile_code' => $profile, 'passport' => ['name' => 'Тестовый сосуд']])->assertCreated()->json('data.id');
        app(TenantContext::class)->set(Tenant::where('slug', 'maintenance-pages')->firstOrFail());
        foreach ([['ТО-1', '2026-01-10'], ['ТО-1', '2026-02-01'], ['ЭПБ', '2026-01-20']] as [$kind, $date]) {
            DB::connection('tenant')->table('equipment_inspections')->insert(['id' => (string) Str::uuid(), 'equipment_id' => $equipment, 'kind' => $kind, 'performed_on' => $date, 'created_at' => now(), 'updated_at' => now()]);
        }
        app(TenantContext::class)->set(null);
        $this->getJson($url.'?kind=repair')->assertOk()->assertJsonPath('data.0.performed_inspections', 1);
    }

    public function test_stop_window_is_clipped_and_no_stops_is_explicit_not_an_empty_list(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0));
        $this->login('maintenance-window');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $this->getJson($url.'/summary')->assertJsonPath('data.no_stops', null);
        $this->patchJson($url, ['version' => 1, 'no_stops' => true])->assertOk();
        $this->getJson($url.'/summary')->assertJsonPath('data.no_stops', true);
        $entries = [$this->entry('stop', ['starts_at' => '2025-11-08T10:00:00Z', 'ends_at' => '2025-11-08T14:00:00Z']), $this->entry('stop', ['starts_at' => '2026-11-08T11:00:00Z', 'ends_at' => '2026-11-08T15:00:00Z'])];
        $this->patchJson($url, ['version' => 2, 'upserts' => $entries])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'no_stops' => false, 'upserts' => $entries])->assertOk();
        $this->getJson($url.'/summary')->assertJsonPath('data.downtime_hours', 3);
        $this->patchJson('/api/installations/'.$id.'/costs', ['version' => 3, 'values' => ['prodcost' => 0]])->assertOk();
        $this->getJson($url.'/summary')->assertJsonPath('data.production_loss', 0);
        $this->patchJson($url, ['version' => 4, 'upserts' => ['bad']])->assertUnprocessable();
    }

    public function test_shared_input_contract_preserves_boolean_zero_and_matches_frozen_parameter_kinds(): void
    {
        $schema = json_decode(file_get_contents(resource_path('schemas/installation-maintenance-v1.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (config('installation-maintenance.fields') as $kind => $fields) {
            $this->assertSame(array_keys($fields), array_keys($schema['kinds'][$kind]['fields']));
            $this->assertSame(app(InstallationMaintenance::class)->requiredFields($kind), $schema['kinds'][$kind]['required']);
        }
        $this->assertSame(array_keys(config('installation-maintenance.repair_kinds')), $schema['repair_kinds']);
        $id = strtoupper((string) Str::uuid());
        $input = app(InstallationMaintenanceInputValidation::class)->validated(['version' => 1, 'no_stops' => 0, 'upserts' => [['id' => $id, 'kind' => 'mri', 'values' => ['reason' => '  '], 'verified' => '0', 'unlock' => 1]]]);
        $this->assertFalse($input['no_stops']);
        $this->assertFalse($input['upserts'][0]['verified']);
        $this->assertTrue($input['upserts'][0]['unlock']);
        $this->assertSame(strtolower($id), $input['upserts'][0]['id']);
        $this->assertNull($input['upserts'][0]['values']['reason']);
    }

    public function test_typed_parameters_preserve_microseconds_and_bind_verification_to_current_values(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 8)->setTime(12, 0, 0, 0));
        $this->login('maintenance-sql-shape');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $mri = $this->entry('mri', ['effective_date' => '2026-01-01', 'interval_months' => 12], true);
        $stop = $this->entry('stop', ['starts_at' => '2026-11-08T10:00:00.123456+03:00', 'ends_at' => '2026-11-08T12:00:00.654321+03:00']);
        $repair = $this->entry('repair', ['starts_on' => null, 'ends_on' => null, 'repair_kind' => null, 'reason' => null]);
        $this->patchJson($url, ['version' => 1, 'upserts' => [$mri, $stop, $repair]])->assertOk();
        $response = $this->getJson($url.'?kind=stop')->assertOk()->assertJsonPath('data.0.values.starts_at', '2026-11-08T07:00:00.123456Z')->json('data.0');
        $this->assertEqualsWithDelta(2.0001474625, $response['duration_hours'], 1e-10);
        $this->getJson($url.'/summary')->assertOk()->assertJsonPath('data.downtime_hours', fn ($value) => abs($value - 2.0001474625) < 1e-10);
        app(TenantContext::class)->set(Tenant::where('slug', 'maintenance-sql-shape')->firstOrFail());
        $db = DB::connection('tenant');
        $this->assertFalse(Schema::connection('tenant')->hasColumn('installation_maintenance_records', 'values'));
        $signature = $db->table('installation_maintenance_records')->where('id', $mri['id'])->value('verification_signature');
        $this->assertSame(64, strlen($signature));
        $invalid = [
            fn () => $db->table('installation_mri_parameters')->where('record_id', $mri['id'])->update(['interval_months' => 0]),
            fn () => $db->table('installation_mri_parameters')->where('record_id', $mri['id'])->update(['interval_months' => 24]),
            fn () => $db->table('installation_repair_parameters')->where('record_id', $repair['id'])->update(['repair_kind' => 'other']),
            fn () => $db->table('installation_stop_parameters')->where('record_id', $stop['id'])->update(['ends_at' => '2026-11-08T06:00:00Z']),
            fn () => $db->table('installation_maintenance_presence')->where('record_id', $mri['id'])->where('field_key', 'effective_date')->delete(),
            fn () => $db->table('installation_maintenance_records')->where('id', $mri['id'])->update(['kind' => 'repair']),
            fn () => $db->table('installation_repair_parameters')->insert(['record_id' => $mri['id']]),
            fn () => $db->table('installations')->where('id', $id)->update(['no_stops' => true]),
        ];
        foreach ($invalid as $index => $write) {
            $db->beginTransaction();
            try {
                $write();
                $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->fail('Invalid maintenance state must be rejected.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->getCode(), ['23514', '23503']);
                if ($index === 1) {
                    $this->assertStringContainsString('explicitly unlock or reverify', $exception->getMessage());
                }
            } finally {
                $db->rollBack();
            }
        }
        $this->patchJson($url, ['version' => 2, 'upserts' => [['id' => $mri['id'], 'kind' => 'mri', 'verified' => 0]]])->assertOk();
        $this->getJson($url.'?kind=mri')->assertOk()->assertJsonPath('data.0.check', null);
        $this->patchJson($url, ['version' => 3, 'upserts' => [['id' => $mri['id'], 'kind' => 'mri', 'values' => ['interval_months' => 24], 'verified' => true]]])->assertOk();
        $this->getJson($url.'?kind=mri')->assertOk()->assertJsonPath('data.0.values.interval_months', 24)->assertJsonPath('data.0.check.by', auth()->id());
        app(TenantContext::class)->set(Tenant::where('slug', 'maintenance-sql-shape')->firstOrFail());
        $this->assertNotSame($signature, DB::connection('tenant')->table('installation_maintenance_records')->where('id', $mri['id'])->value('verification_signature'));
    }

    public function test_mri_date_swaps_and_soft_delete_reuse_keep_a_single_active_date_owner(): void
    {
        $this->login('maintenance-mri-dates');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $a = $this->entry('mri', ['effective_date' => '2026-01-01', 'interval_months' => 12]);
        $b = $this->entry('mri', ['effective_date' => '2026-07-01', 'interval_months' => 24]);
        $this->patchJson($url, ['version' => 1, 'upserts' => [$a, $b]])->assertOk();
        $this->patchJson($url, ['version' => 2, 'upserts' => [['id' => $a['id'], 'kind' => 'mri', 'values' => ['effective_date' => '2026-07-01']], ['id' => $b['id'], 'kind' => 'mri', 'values' => ['effective_date' => '2026-01-01']]]])->assertOk();
        $this->patchJson($url, ['version' => 3, 'delete_ids' => [$a['id']]])->assertOk();
        $c = $this->entry('mri', ['effective_date' => '2026-07-01', 'interval_months' => 6]);
        $this->patchJson($url, ['version' => 4, 'upserts' => [$c]])->assertOk();
        app(TenantContext::class)->set(Tenant::where('slug', 'maintenance-mri-dates')->firstOrFail());
        $db = DB::connection('tenant');
        $this->assertNull($db->table('installation_mri_parameters')->where('record_id', $a['id'])->value('active_slot'));
        $db->beginTransaction();
        try {
            $db->table('installation_maintenance_records')->where('id', $a['id'])->update(['deleted_at' => null]);
            $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
            $this->fail('Restoring duplicate MRI date must fail.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->getCode());
        } finally {
            $db->rollBack();
        }
        $this->assertNotNull($db->table('installation_maintenance_records')->where('id', $a['id'])->value('deleted_at'));
        $this->patchJson($url, ['version' => 5, 'upserts' => [$this->entry('mri'), $this->entry('mri')]])->assertOk();
    }

    public function test_maintenance_migration_roundtrip_preserves_deleted_records_checks_and_fractional_timestamps(): void
    {
        $this->login('maintenance-backfill');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/maintenance';
        $mri = $this->entry('mri', ['effective_date' => '2026-01-01', 'interval_months' => 12], true);
        $repair = $this->entry('repair', ['starts_on' => '2026-07-05', 'ends_on' => '2026-07-12', 'repair_kind' => 'capital', 'reason' => null], true);
        $stop = $this->entry('stop', ['starts_at' => '2026-11-08T07:00:00.123456Z', 'ends_at' => '2026-11-08T09:00:00.654321Z']);
        $this->patchJson($url, ['version' => 1, 'upserts' => [$mri, $repair, $stop]])->assertOk();
        $this->patchJson($url, ['version' => 2, 'delete_ids' => [$stop['id']]])->assertOk();
        app(TenantContext::class)->set(Tenant::where('slug', 'maintenance-backfill')->firstOrFail());
        $db = DB::connection('tenant');
        $before = $db->table('installation_maintenance_cards')->orderBy('record_id')->get()->map(fn ($row) => (array) $row)->all();
        $path = 'database/tenant-migrations';
        $rollback = fn () => Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $db->table('migrations')->where('migration', '>=', '2026_11_10_000061')->count(), '--force' => true]);
        $this->assertSame(0, $rollback());
        $this->assertTrue(Schema::connection('tenant')->hasColumn('installation_maintenance_records', 'values'));
        foreach ($before as $row) {
            $legacy = $db->table('installation_maintenance_records')->where('id', $row['record_id'])->first();
            $this->assertEquals(json_decode($row['values'], true), json_decode($legacy->values, true));
            $this->assertEquals(json_decode($row['check'] ?? 'null', true), json_decode($legacy->check ?? 'null', true));
        }
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
        $this->assertEquals($before, $db->table('installation_maintenance_cards')->orderBy('record_id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertNotNull($db->table('installation_maintenance_records')->where('id', $stop['id'])->value('deleted_at'));
        $this->assertSame(0, $rollback());
        $legacyValues = json_decode($db->table('installation_maintenance_records')->where('id', $mri['id'])->value('values'), true);
        $db->table('installation_maintenance_records')->where('id', $mri['id'])->update(['values' => json_encode($legacyValues + ['unsupported' => 1])]);
        try {
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]);
            $this->fail('Unknown legacy field must stop normalization.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unknown maintenance field', $exception->getMessage());
        }
        $this->assertFalse(Schema::connection('tenant')->hasTable('installation_mri_parameters'));
        $this->assertSame(1, json_decode($db->table('installation_maintenance_records')->where('id', $mri['id'])->value('values'), true)['unsupported']);
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
