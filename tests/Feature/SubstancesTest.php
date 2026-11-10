<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\Catalogs\Application\CatalogRecords;
use App\Modules\Catalogs\Application\CatalogSnapshotUpdate;
use App\Modules\Catalogs\Domain\SubstancePropertySchema;
use App\Modules\Catalogs\Infrastructure\SubstanceBaseline;
use App\Modules\Catalogs\Infrastructure\SubstanceCardProjection;
use App\Modules\Catalogs\Models\Substance;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubstancesTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private function tenant(string $slug): Tenant
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

    public function test_baseline_is_versioned_and_tenant_edits_do_not_change_base_or_other_tenants(): void
    {
        $alpha = $this->tenant('substance-alpha');
        $beta = $this->tenant('substance-beta');
        $user = $this->login($alpha);
        $this->getJson('/api/substances')->assertOk()->assertJsonPath('meta.total', 104)->assertJsonCount(50, 'data');
        $found = $this->getJson('/api/substances?q='.urlencode('Метан'))->assertOk()->json('data');
        $id = collect($found)->firstWhere('name', 'Метан')['id'];
        $base = $this->getJson('/api/substances/'.$id)->assertOk()->assertJsonPath('data.values.mw', 16)->assertJsonPath('data.origin', 'base')->json('data');
        $this->assertStringStartsWith('frontend-', $base['base_version']);
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'values' => ['mw' => '16,04', 'flammable' => false], 'sources' => ['mw' => ['citation' => 'Протокол лаборатории', 'conditions' => 'Условия испытания']], 'verification' => ['flammable' => true]])
            ->assertOk()->assertJsonPath('data.values.mw', 16.04)->assertJsonPath('data.base_values.mw', 16)->assertJsonPath('data.values.flammable', false)->assertJsonPath('data.checks.flammable.by', $user->id)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'values' => ['mw' => 20]])->assertConflict();
        app(TenantContext::class)->set($alpha);
        app(SubstanceBaseline::class)->seed();
        app(TenantContext::class)->set(null);
        $this->getJson('/api/substances/'.$id)->assertOk()->assertJsonPath('data.values.mw', 16.04)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/substances/'.$id, ['version' => 2, 'reset_fields' => ['mw']])->assertOk()->assertJsonPath('data.values.mw', 16)->assertJsonMissingPath('data.overrides.mw');
        $this->getJson('/api/substances/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.changes.0.key', 'mw');
        $this->login($beta);
        $this->getJson('/api/substances/'.$id)->assertOk()->assertJsonPath('data.values.mw', 16)->assertJsonPath('data.values.flammable', true)->assertJsonPath('data.version', 1);
    }

    public function test_combined_filters_use_or_for_phases_and_and_for_hazards_and_units_are_typed(): void
    {
        $this->login($this->tenant('substance-combined'));
        $items = [['газ', true, true], ['жидкость', true, true], ['твёрдое', true, true], ['газ', true, false]];
        foreach ($items as $index => [$phase, $flammable, $toxic]) {
            $item = $this->postJson('/api/substances', ['values' => ['name' => 'COMBINED-'.$index, 'phaseNu' => $phase, 'flammable' => $flammable, 'toxic' => $toxic, 'idlh' => 10, 'idlhUnit' => 'mg/m3']])->assertCreated()->assertJsonPath('data.values.idlhUnit', 'mg/m3');
            $id = $item->json('data.id');
        }
        $this->getJson('/api/substances?q=COMBINED&phases[]=газ&phases[]=жидкость&criteria[]=flammable&criteria[]=toxic')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/substances?criteria[]=bad')->assertUnprocessable();
        $this->getJson('/api/substances?phases[]=bad')->assertUnprocessable();
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'verification' => ['idlh' => true]])->assertOk();
        $this->patchJson('/api/substances/'.$id, ['version' => 2, 'values' => ['idlhUnit' => 'ppm']])->assertOk()->assertJsonMissingPath('data.checks.idlh');
        $this->patchJson('/api/substances/'.$id, ['version' => 3, 'values' => ['idlhUnit' => 'kg']])->assertUnprocessable();
    }

    public function test_main_snapshot_update_preserves_divergence_checks_and_is_repeatable(): void
    {
        $tenant = $this->tenant('catalog-three-way');
        $user = $this->login($tenant);
        $id = $this->getJson('/api/substances?q='.urlencode('Метан'))->json('data.0.id');
        $card = $this->getJson('/api/substances/'.$id)->json('data');
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'values' => ['dens' => 999], 'verification' => ['mw' => true]])->assertOk();
        $entry = ['code' => 'chem1', 'before' => $card['values'], 'after' => array_replace($card['values'], ['mw' => 17, 'dens' => 500, 'nbp' => -160]), 'archived' => false];
        $snapshot = ['version' => 'test', 'to' => 'test', 'entries' => [$entry]];
        app(TenantContext::class)->set($tenant);
        $service = app(CatalogSnapshotUpdate::class);
        $report = $service->run($snapshot, $user->id);
        $this->assertCount(2, $report['conflicts']);
        $this->assertSame(['nbp'], $report['updated'][0]['fields']);
        $this->assertSame(2, Substance::findOrFail($id)->version);
        $service->run($snapshot, $user->id, true);
        $card = Substance::findOrFail($id);
        $this->assertSame(999, $card->effective()['dens']);
        $this->assertSame(16, $card->effective()['mw']);
        $this->assertSame(-160, $card->effective()['nbp']);
        $this->assertArrayHasKey('mw', $card->checks);
        $this->assertSame([], $service->run($snapshot, $user->id, true)['updated']);
    }

    public function test_source_ranges_are_annotations_and_never_become_accepted_scalar_numbers(): void
    {
        $this->login($this->tenant('substance-ranges'));
        $created = $this->postJson('/api/substances', ['values' => ['name' => 'Диапазоны источников', 'nbp' => 252, 'nbp_icsc' => '252..255', 'dens_cameo' => '>800', 'mw_kor' => 'varies', 'idlh' => 'не определено']])->assertCreated();
        $id = $created->json('data.id');
        $created->assertJsonPath('data.values.nbp', 252)->assertJsonPath('data.values.nbp_icsc', '252..255')->assertJsonPath('data.values.dens_cameo', '>800');
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'verification' => ['nbp_icsc' => true]])->assertOk()->assertJsonPath('data.checks.nbp_icsc.by', $this->app['auth']->user()->id);
        foreach ([['nbp' => '252..255'], ['nbp_icsc' => '255..252'], ['mw_cameo' => '<-1'], ['dens_cameo' => str_repeat('0', 150).'1..2']] as $invalid) {
            $this->patchJson('/api/substances/'.$id, ['version' => 2, 'values' => $invalid])->assertUnprocessable();
        }
        $this->patchJson('/api/substances/'.$id, ['version' => 2, 'values' => ['nbp_icsc' => 253]])->assertOk()->assertJsonPath('data.values.nbp_icsc', 253)->assertJsonMissingPath('data.checks.nbp_icsc');
    }

    public function test_custom_values_preserve_zero_false_missing_state_and_verification(): void
    {
        $user = $this->login($this->tenant('substance-custom'));
        $created = $this->postJson('/api/substances', ['values' => ['name' => 'Тестовый реагент', 'formula' => 'X', 'pdk' => 0, 'inert' => false, 'idlh' => 'не установлено', 'hazClass' => '1', 'grpRF' => 'А(а)']])
            ->assertCreated()->assertJsonPath('data.values.pdk', 0)->assertJsonPath('data.values.inert', false)->assertJsonPath('data.values.idlh', 'не установлено')->assertJsonPath('data.base_values', [])->json('data');
        $id = $created['id'];
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'verification' => ['pdk' => true, 'inert' => true]])->assertOk()->assertJsonPath('data.checks.pdk.by', $user->id)->assertJsonPath('data.checks.inert.by', $user->id);
        $this->patchJson('/api/substances/'.$id, ['version' => 2, 'values' => ['pdk' => null]])->assertOk()->assertJsonPath('data.values.pdk', null)->assertJsonMissingPath('data.checks.pdk');
        $this->getJson('/api/substances?q='.urlencode('Тестовый реагент').'&criterion=inert&criterion_value=no')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/substances?q='.urlencode('Тестовый реагент').'&criterion=toxic&criterion_value=unknown')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/substances', ['values' => ['name' => 'Тестовый реагент']])->assertUnprocessable()->assertJsonValidationErrors('values.name');
        $this->patchJson('/api/substances/'.$id, ['version' => 3, 'status' => 'archived'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson('/api/substances?q='.urlencode('Тестовый реагент'))->assertOk()->assertJsonPath('meta.total', 0);
        $this->patchJson('/api/substances/'.$id, ['version' => 4, 'status' => 'active'])->assertOk()->assertJsonPath('data.id', $id);
    }

    public function test_validation_provenance_and_permissions_do_not_allow_source_replacement(): void
    {
        $alpha = $this->tenant('substance-permissions');
        $beta = $this->tenant('substance-foreign');
        $this->login($alpha);
        foreach ([['mw' => -1], ['mw' => 'NaN'], ['nfpaH' => 5], ['flammable' => 'false'], ['grpRFNote' => 'старое поле'], ['mix' => [['n' => 'X', 'p' => 100]]]] as $bad) {
            $this->postJson('/api/substances', ['values' => ['name' => 'Некорректное вещество'] + $bad])->assertUnprocessable();
        }
        $id = $this->postJson('/api/substances', ['values' => ['name' => 'Частное вещество', 'grpRF' => 'А', 'pdk' => '1,05', 'hazClass' => '4']])->assertCreated()->json('data.id');
        $this->getJson('/api/substances/'.$id)->assertOk()->assertJsonPath('data.values.hazClass', '4')->assertJsonCount(3, 'data.classification.warnings');
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'base_values' => ['mw' => 10]])->assertUnprocessable();
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'verification' => ['mw' => true]])->assertUnprocessable();
        $this->login($beta);
        $this->getJson('/api/substances/'.$id)->assertNotFound();
        $this->login($alpha, 'member');
        $this->getJson('/api/substances/'.$id)->assertOk();
        $this->postJson('/api/substances', ['values' => ['name' => 'Запрещено']])->assertForbidden();
        $this->patchJson('/api/substances/'.$id, ['version' => 1, 'status' => 'archived'])->assertForbidden();
    }

    public function test_runtime_substance_contract_matches_the_frozen_relational_schema(): void
    {
        $fields = SubstancePropertySchema::fields();
        $this->assertSame(array_keys(config('substances.fields')), array_keys($fields));
        foreach (config('substances.fields') as $key => $field) {
            $this->assertSame($field['type'], $fields[$key]['type'], $key);
            $this->assertSame($field['readonly'] ?? false, $fields[$key]['column'] === null, $key);
            foreach (['min', 'max', 'options'] as $property) {
                $this->assertSame($field[$property] ?? null, $fields[$key][$property] ?? null, $key.' '.$property);
            }
            if (in_array($field['type'], ['number', 'integer'], true)) {
                $this->assertSame([...config('substances.missing_values'), ...($field['qualifiers'] ?? [])], $fields[$key]['qualifiers'], $key);
            }
        }
        $this->assertSame(SubstanceCardProjection::definition().";\n", file_get_contents(resource_path('schemas/substance-cards-v2.sql')));
    }

    public function test_relational_values_preserve_unknown_zero_false_and_reject_conflicting_sql_states(): void
    {
        $tenant = $this->tenant('substance-sql-shape');
        $actor = User::factory()->create();
        app(TenantContext::class)->set($tenant);
        $records = app(CatalogRecords::class);
        $model = $records->create(Substance::class, ['values' => ['name' => 'Физические свойства', 'pdk' => 0, 'flammable' => false, 'idlh' => 'не определено', 'mw' => null]], $actor->id, 'substance');
        $this->assertFalse(Schema::connection('tenant')->hasColumn('substances', 'overrides'));
        $this->assertSame(0.0, (float) DB::connection('tenant')->table('substance_properties')->where('substance_id', $model->id)->value('pdk'));
        $this->assertFalse($model->fresh()->effective()['flammable']);
        $this->assertArrayHasKey('mw', $model->fresh()->overrides);
        $this->assertNull($model->fresh()->effective()['mw']);
        $this->assertSame('не определено', $model->fresh()->effective()['idlh']);
        $db = DB::connection('tenant');
        $invalid = [
            fn () => $db->table('substance_properties')->where('substance_id', $model->id)->update(['pdk' => -1]),
            fn () => $db->table('substance_properties')->where('substance_id', $model->id)->update(['idlh' => 10]),
            fn () => $db->table('substance_property_annotations')->where('substance_id', $model->id)->where('field_key', 'pdk')->update(['value_present' => false]),
            fn () => $db->table('substance_property_annotations')->where('substance_id', $model->id)->where('field_key', 'idlh')->update(['qualifier' => 'произвольный текст']),
            fn () => $db->table('substance_property_annotations')->where('substance_id', $model->id)->where('field_key', 'mw')->update(['verified_by' => $actor->id, 'verified_at' => now()]),
        ];
        foreach ($invalid as $write) {
            $db->beginTransaction();
            try {
                $write();
                $db->statement('SET CONSTRAINTS ALL IMMEDIATE');
                $this->fail('Invalid substance state must be rejected by PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertContains($exception->errorInfo[0], ['23514', '23503']);
            } finally {
                $db->rollBack();
            }
        }
        $updated = $records->update(Substance::class, $model->id, ['version' => 1, 'reset_fields' => ['mw', 'idlh'], 'verification' => ['pdk' => true, 'flammable' => true]], $actor->id, 'substance');
        $this->assertArrayNotHasKey('mw', $updated->overrides);
        $this->assertArrayNotHasKey('idlh', $updated->overrides);
        $this->assertArrayHasKey('pdk', $updated->checks);
        $this->assertFalse($updated->effective()['flammable']);
    }

    public function test_substance_normalization_backfills_and_rolls_back_without_changing_values_or_evidence(): void
    {
        $tenant = $this->tenant('substance-backfill');
        app(TenantContext::class)->set($tenant);
        $db = DB::connection('tenant');
        $path = 'database/tenant-migrations/2026_11_10_000056_normalize_substance_properties.php';
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $db->table('migrations')->where('migration', '>=', '2026_11_10_000056')->count(), '--force' => true]));
        $db = DB::connection('tenant');
        $id = $db->table('substances')->where('legacy_code', 'chem1')->value('id');
        $values = ['mw' => null, 'pdk' => 0, 'idlh' => 'не определено', 'flammable' => false];
        $sources = ['mw' => ['citation' => 'Протокол', 'conditions' => null, 'by' => 1, 'at' => '2026-11-10T00:00:00+00:00']];
        $checks = ['pdk' => ['by' => 1, 'at' => '2026-11-10T00:00:00+00:00']];
        $db->table('substances')->where('id', $id)->update(['overrides' => json_encode($values), 'sources' => json_encode($sources), 'checks' => json_encode($checks)]);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
        $model = Substance::findOrFail($id);
        $this->assertEquals($values, $model->overrides);
        $this->assertEquals($sources, $model->sources);
        $this->assertEquals($checks, $model->checks);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $db->table('migrations')->where('migration', '>=', '2026_11_10_000056')->count(), '--force' => true]));
        $legacy = $db->table('substances')->where('id', $id)->first();
        $this->assertEquals($values, json_decode($legacy->overrides, true));
        $this->assertEquals($sources, json_decode($legacy->sources, true));
        $this->assertEquals($checks, json_decode($legacy->checks, true));
        // Unknown legacy keys abort the whole DDL transaction rather than silently dropping values.
        $db->table('substances')->where('id', $id)->update(['overrides' => json_encode($values + ['unsupported_key' => 1])]);
        try {
            Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]);
            $this->fail('Unknown field must stop normalization.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unknown substance properties', $exception->getMessage());
        }
        $this->assertFalse(Schema::connection('tenant')->hasTable('substance_properties'));
        $this->assertSame(1, json_decode($db->table('substances')->where('id', $id)->value('overrides'), true)['unsupported_key']);
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
