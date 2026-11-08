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
