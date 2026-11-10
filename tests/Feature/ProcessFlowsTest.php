<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Modules\ProcessFlows\Application\FlowInputValidation;
use App\Modules\ProcessFlows\Domain\MixtureCalculator;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProcessFlowsTest extends TestCase
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

        return $this->postJson('/api/installations', ['name' => 'Установка потоков', 'parent_id' => $parent])->assertCreated()->json('data.id');
    }

    private function flow(string $installation, int $version = 1): array
    {
        return $this->postJson('/api/installations/'.$installation.'/flow-nodes', ['structure_version' => $version, 'kind' => 'flow', 'category' => 'main', 'name' => 'Основной поток'])->assertCreated()->json('data');
    }

    private function substance(string $name, ?float $mw, float $nbp, float $density): string
    {
        return $this->postJson('/api/substances', ['values' => ['name' => $name, 'mw' => $mw, 'nbp' => $nbp, 'dens' => $density, 'flammable' => true, 'toxic' => false, 'nfnt' => false, 'inert' => false]])->assertCreated()->json('data.id');
    }

    private function compositionLine(string $id, float $amount): array
    {
        return ['id' => (string) Str::uuid(), 'substance_id' => $id, 'role' => 'main', 'amount' => $amount];
    }

    public function test_atmosphere_uses_catalog_mixture_but_soil_keeps_material_reference_rows(): void
    {
        $this->login('external-composition');
        $installation = $this->installation();
        $substance = $this->substance('Газ атмосферы', 28, -196, 800);
        $air = $this->postJson('/api/installations/'.$installation.'/flow-nodes', ['structure_version' => 1, 'kind' => 'flow', 'category' => 'external', 'name' => 'Атмосфера', 'medium_kind' => 'air'])->assertCreated()->json('data');
        $this->patchJson('/api/process-flows/'.$air['id'], ['version' => 1, 'composition' => [$this->compositionLine($substance, 100)], 'apply_calculation' => true])->assertOk()->assertJsonPath('data.proposed_calculation.complete', true)->assertJsonPath('data.accepted_calculation.properties.molecular_weight', 28);
        $soil = $this->postJson('/api/installations/'.$installation.'/flow-nodes', ['structure_version' => 2, 'kind' => 'flow', 'category' => 'external', 'name' => 'Грунт', 'medium_kind' => 'soil'])->assertCreated()->json('data');
        $this->patchJson('/api/process-flows/'.$soil['id'], ['version' => 1, 'composition' => [$this->compositionLine($substance, 100)]])->assertUnprocessable()->assertJsonValidationErrors('composition');
        $this->patchJson('/api/process-flows/'.$soil['id'], ['version' => 1, 'composition' => [['id' => (string) Str::uuid(), 'role' => 'reference', 'name' => 'Вода в грунте', 'amount' => 20, 'unit' => 'mass_percent']]])->assertOk()->assertJsonPath('data.proposed_calculation', null)->assertJsonPath('data.composition.0.amount', 20);
    }

    public function test_flow_codes_are_searchable_unique_per_installation_and_keep_identity_when_changed(): void
    {
        $this->login('flow-codes');
        $installation = $this->installation();
        $forged = (string) Str::uuid();
        $flow = $this->postJson('/api/installations/'.$installation.'/flow-nodes', ['id' => $forged, 'structure_version' => 1, 'kind' => 'flow', 'category' => 'main', 'name' => 'Газ', 'code' => ' ОП1.1-1 '])->assertCreated()->assertJsonPath('data.code', 'ОП1.1-1')->json('data');
        $this->assertNotSame($forged, $flow['id']);
        $this->getJson('/api/installations/'.$installation.'/flow-nodes?q='.urlencode('ОП1.1-1'))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.code', 'ОП1.1-1');
        $this->postJson('/api/installations/'.$installation.'/flow-nodes', ['structure_version' => 2, 'kind' => 'flow', 'category' => 'main', 'name' => 'Другой газ', 'code' => 'ОП1.1-1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $updated = $this->patchJson('/api/flow-nodes/'.$flow['id'], ['version' => 1, 'structure_version' => 2, 'code' => 'ОП1.1-2'])->assertOk()->assertJsonPath('data.id', $flow['id'])->assertJsonPath('data.code', 'ОП1.1-2')->json('data');
        $other = $this->installation();
        $this->postJson('/api/installations/'.$other.'/flow-nodes', ['structure_version' => 1, 'kind' => 'flow', 'category' => 'main', 'name' => 'Газ', 'code' => 'ОП1.1-2'])->assertCreated();
        $this->getJson('/api/process-flows/'.$updated['id'])->assertOk()->assertJsonPath('data.code', 'ОП1.1-2');
    }

    public function test_http_and_maintenance_share_locale_numbers_unknowns_and_nested_validation(): void
    {
        $this->login('shared-flow-inputs');
        $flow = $this->flow($this->installation());
        $input = ['version' => 1, 'attributes' => ['flow_rate_t_h' => "1\u{202F}234,5", 'velocity_m_s' => '0', 'density' => ''],
            'composition' => [['id' => (string) Str::uuid(), 'role' => 'impurity', 'name' => ' Соли ', 'amount' => '50,5', 'unit' => 'mass_percent']],
            'screening' => ['values' => ['h2' => false, 'cl' => '1,25']],
            'sources' => ['flow_rate_t_h' => ['citation' => ' Протокол ', 'conditions' => '']]];
        $canonical = app(FlowInputValidation::class)->card($input);
        $this->assertSame(1234.5, $canonical['attributes']['flow_rate_t_h']);
        $this->assertSame(0.0, $canonical['attributes']['velocity_m_s']);
        $this->assertNull($canonical['attributes']['density']);
        $this->assertSame(false, $canonical['screening']['values']['h2']);
        $result = $this->patchJson('/api/process-flows/'.$flow['id'], $input)->assertOk()->json('data');
        $this->assertEquals($canonical['attributes'], $result['attributes']);
        $this->assertEquals(50.5, $result['composition'][0]['amount']);
        $this->assertSame('Соли', $result['composition'][0]['name']);
        $this->assertSame('Протокол', $result['sources']['flow_rate_t_h']['citation']);
        $this->assertFalse($result['screening']['values']['h2']);
        foreach ([['composition' => ['broken']], ['attributes' => ['flow_rate_t_h' => '1e999']], ['attributes' => ['flow_rate_t_h' => '0x10']], ['screening' => 'broken']] as $invalid) {
            $this->patchJson('/api/process-flows/'.$flow['id'], ['version' => $result['version']] + $invalid)->assertUnprocessable();
            try {
                app(FlowInputValidation::class)->card(['version' => $result['version']] + $invalid);
                $this->fail('Maintenance input must reject the same invalid data.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_flow_rate_and_velocity_are_independent_typed_inputs_with_sources_and_zero(): void
    {
        $this->login('flow-rate-and-velocity');
        $flow = $this->flow($this->installation());
        $url = '/api/process-flows/'.$flow['id'];
        $saved = $this->patchJson($url, ['version' => 1, 'attributes' => ['flow_rate_t_h' => '0', 'velocity_m_s' => '11,8'],
            'sources' => ['flow_rate_t_h' => ['citation' => 'Технологический регламент', 'conditions' => 'т/ч']], 'verification' => ['flow_rate_t_h' => true]])
            ->assertOk()->assertJsonPath('data.attributes.flow_rate_t_h', 0)->assertJsonPath('data.attributes.velocity_m_s', 11.8)->assertJsonMissingPath('data.attributes.pressure')->json('data');
        $this->getJson($url)->assertOk()->assertJsonPath('data.sources.flow_rate_t_h.citation', 'Технологический регламент')->assertJsonPath('data.checks.flow_rate_t_h.by', auth()->id());
        $this->patchJson($url, ['version' => $saved['version'], 'attributes' => ['velocity_m_s' => -1]])->assertUnprocessable();
        $this->patchJson($url, ['version' => $saved['version'], 'attributes' => ['velocity_m_s' => null]])->assertOk()->assertJsonPath('data.attributes.velocity_m_s', null)->assertJsonPath('data.attributes.flow_rate_t_h', 0);
        $schema = $this->getJson('/api/process-flows/schema')->assertOk()->json('fields');
        $this->assertSame('т/ч', collect($schema)->firstWhere('key', 'flow_rate_t_h')['unit']);
        $this->assertSame('м/с', collect($schema)->firstWhere('key', 'velocity_m_s')['unit']);
    }

    public function test_normalization_roundtrip_preserves_sparse_values_sources_checks_and_calculation_ownership(): void
    {
        $this->login('flow-normalization');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $other = $this->flow($installation, 2);
        $substance = $this->substance('Вещество миграции', 20, 30, 1000);
        $url = '/api/process-flows/'.$flow['id'];
        $saved = $this->patchJson($url, ['version' => 1, 'attributes' => ['temperature' => 0, 'ph' => null], 'sources' => ['temperature' => ['citation' => 'Протокол', 'conditions' => 'Без подстановки']], 'verification' => ['temperature' => true],
            'composition' => [$this->compositionLine($substance, 100)], 'apply_calculation' => true])->assertOk()->json('data');
        app(TenantContext::class)->set(Tenant::where('slug', 'flow-normalization')->firstOrFail());
        try {
            $db = DB::connection('tenant');
            $path = 'database/tenant-migrations';
            $steps = $db->table('migrations')->where('migration', '>=', '2026_11_09_000024')->count();
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $steps, '--force' => true]));
            $sources = json_decode($db->table('process_flow_nodes')->where('id', $flow['id'])->value('sources'), true);
            $sources['temperature']['at'] = '2026-11-09T04:05:06.123456+05:00';
            $db->table('process_flow_nodes')->where('id', $flow['id'])->update(['sources' => json_encode($sources, JSON_THROW_ON_ERROR)]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
            $this->assertEquals(0, $db->table('process_flow_properties')->where('flow_id', $flow['id'])->value('temperature'));
            $this->assertNull($db->table('process_flow_properties')->where('flow_id', $flow['id'])->value('ph'));
            $this->assertTrue($db->table('process_flow_property_annotations')->where('flow_id', $flow['id'])->where('field_key', 'ph')->value('value_present'));
            $this->assertSame($saved['accepted_calculation']['id'], $db->table('process_flow_nodes')->where('id', $flow['id'])->value('accepted_calculation_id'));
            try {
                $db->transaction(fn () => $db->table('process_flow_nodes')->where('id', $other['id'])->update(['accepted_calculation_id' => $saved['accepted_calculation']['id']]));
                $this->fail('Foreign calculation must be rejected.');
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->getCode());
            }
            try {
                $db->transaction(fn () => $db->table('process_flow_calculations')->where('id', $saved['accepted_calculation']['id'])->update(['signature' => str_repeat('0', 64)]));
                $this->fail('Calculation snapshot must be immutable.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('data.attributes.temperature', 0)->assertJsonPath('data.attributes.ph', null)->assertJsonMissingPath('data.attributes.density')
            ->assertJsonPath('data.sources.temperature.at', '2026-11-08T23:05:06.123456+00:00')->assertJsonPath('data.sources.temperature.conditions', 'Без подстановки')->assertJsonPath('data.accepted_calculation.id', $saved['accepted_calculation']['id']);
    }

    public function test_wizard_comments_idempotency_tree_cycles_and_independent_versions(): void
    {
        $this->login('flow-tree');
        $id = $this->installation();
        $url = '/api/installations/'.$id.'/flow-nodes';
        $this->getJson($url)->assertOk()->assertJsonPath('initialized', false)->assertJsonPath('structure_version', 1);
        $answers = [];
        foreach (array_keys(config('process-flows.categories')) as $key) {
            $answers[$key] = ['enabled' => in_array($key, ['main', 'auxiliary']) ? true : null, 'comment' => 'Комментарий '.$key];
        }
        $payload = ['structure_version' => 1, 'request_id' => (string) Str::uuid(), 'answers' => $answers];
        $this->postJson($url.'/initialize', $payload)->assertOk()->assertJsonPath('structure_version', 2);
        $this->postJson($url.'/initialize', $payload)->assertOk()->assertJsonPath('structure_version', 2);
        $this->getJson($url)->assertJsonPath('meta.total', 2)->assertJsonPath('setup.answers.external.enabled', null);
        $payload['answers']['main']['comment'] = 'Другой ответ';
        $this->postJson($url.'/initialize', $payload)->assertConflict();
        $root = collect($this->getJson($url)->json('data'))->firstWhere('category', 'main');
        $child = $this->postJson($url, ['structure_version' => 2, 'kind' => 'folder', 'category' => 'main', 'name' => 'Группа', 'parent_id' => $root['id']])->assertCreated()->json('data');
        $this->patchJson('/flow-nodes/'.$root['id'], [])->assertNotFound();
        $this->patchJson('/api/flow-nodes/'.$root['id'], ['version' => 1, 'structure_version' => 3, 'parent_id' => $child['id']])->assertUnprocessable();
        $this->patchJson('/api/flow-nodes/'.$root['id'], ['version' => 1, 'structure_version' => 3, 'status' => 'archived'])->assertUnprocessable();
        $this->patchJson('/api/flow-nodes/'.$child['id'], ['version' => 1, 'structure_version' => 3, 'parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
        $this->postJson($url, ['structure_version' => 3, 'kind' => 'flow', 'category' => 'main', 'name' => 'Старый черновик'])->assertConflict();
        $this->getJson('/api/installations/'.$id)->assertJsonPath('data.version', 1);
    }

    public function test_environment_and_ph_share_one_owner_without_turning_missing_into_zero(): void
    {
        $this->login('flow-ph-owner');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $url = '/api/process-flows/'.$flow['id'];
        $this->patchJson($url, ['version' => 1, 'attributes' => ['ph' => 0]])->assertOk()->assertJsonPath('data.attributes.ph', 0)->assertJsonPath('data.screening.values.ph', 0);
        $this->patchJson($url, ['version' => 2, 'verification' => ['ph' => true]])->assertOk();
        $this->patchJson($url, ['version' => 3, 'screening' => ['values' => ['ph' => 7, 'water' => true]]])->assertOk()->assertJsonPath('data.attributes.ph', 7)->assertJsonPath('data.screening.values.ph', 7)->assertJsonMissingPath('data.checks.ph');
        $this->patchJson($url, ['version' => 4, 'attributes' => ['ph' => 0], 'screening' => ['values' => ['ph' => null]]])->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('data.version', 4)->assertJsonPath('data.attributes.ph', 7);
        $this->patchJson($url, ['version' => 4, 'attributes' => ['ph' => 0]])->assertOk()->assertJsonPath('data.screening.values.ph', 0);
        $this->patchJson($url, ['version' => 5, 'screening' => ['values' => ['ph' => null]]])->assertOk()->assertJsonPath('data.attributes.ph', null)->assertJsonMissingPath('data.screening.values.ph');
        $other = $this->flow($installation, 2);
        $this->patchJson('/api/process-flows/'.$other['id'], ['version' => 1, 'screening' => ['values' => ['water' => true, 'ph' => null]]])->assertOk()->assertJsonMissingPath('data.attributes.ph');
    }

    public function test_environment_migration_backfills_legacy_ph_and_rolls_back_conflicts_without_data_loss(): void
    {
        $this->login('environment-migration');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $url = '/api/process-flows/'.$flow['id'];
        $this->patchJson($url, ['version' => 1, 'screening' => ['values' => ['ph' => 0, 'water' => true, 'hcl' => false], 'comments' => ['hcl|3' => 'Протокол', 'water' => ''], 'thresholds' => ['hcl|3' => ['threshold' => [7], 'near' => []]]]])->assertOk();
        app(TenantContext::class)->set(Tenant::where('slug', 'environment-migration')->firstOrFail());
        try {
            $db = DB::connection('tenant');
            $path = 'database/tenant-migrations';
            $steps = $db->table('migrations')->where('migration', '>=', '2026_11_09_000025')->count();
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $steps, '--force' => true]));
            $legacy = json_decode($db->table('process_flow_nodes')->where('id', $flow['id'])->value('screening'), true);
            $legacy['updated_at'] = '2026-11-09T04:05:06.123456+05:00';
            $legacy['comments']['water'] = '';
            $db->table('process_flow_nodes')->where('id', $flow['id'])->update(['screening' => json_encode($legacy, JSON_THROW_ON_ERROR)]);
            $db->table('process_flow_property_annotations')->where('flow_id', $flow['id'])->where('field_key', 'ph')->delete();
            $db->table('process_flow_properties')->where('flow_id', $flow['id'])->update(['ph' => null]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
            $this->assertEquals(0, $db->table('process_flow_properties')->where('flow_id', $flow['id'])->value('ph'));
            $this->assertFalse($db->table('process_flow_environments')->where('flow_id', $flow['id'])->value('hydrogen_chloride_present'));
            $this->assertSame('', $db->table('process_flow_screening_comments')->where('flow_id', $flow['id'])->where('item_id', 'water')->value('comment'));
            try {
                $db->transaction(fn () => $db->table('process_flow_screening_thresholds')->insert(['flow_id' => $flow['id'], 'question_id' => 'hcl|1', 'bound_count' => 1, 'bound_1' => 1, 'bound_2' => null, 'near_count' => 0, 'near_1' => null, 'near_2' => null]));
                $this->fail('Boolean question must not acquire a numeric override.');
            } catch (QueryException $exception) {
                $this->assertSame('23503', $exception->getCode());
            }
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $steps, '--force' => true]));
            $db->table('process_flow_properties')->where('flow_id', $flow['id'])->update(['ph' => null]);
            try {
                Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]);
                $this->fail('Explicit null and legacy zero must conflict.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Conflicting physical and environmental pH', $exception->getMessage());
            }
            $this->assertFalse(Schema::connection('tenant')->hasTable('process_flow_environments'));
            $legacy = json_decode($db->table('process_flow_nodes')->where('id', $flow['id'])->value('screening'), true);
            $this->assertEquals(0, $legacy['values']['ph']);
            $this->assertNull($db->table('process_flow_properties')->where('flow_id', $flow['id'])->value('ph'));
            $db->table('process_flow_properties')->where('flow_id', $flow['id'])->update(['ph' => 0]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
        } finally {
            app(TenantContext::class)->set(null);
        }
        $this->getJson($url)->assertOk()->assertJsonPath('data.attributes.ph', 0)->assertJsonPath('data.screening.values.ph', 0)->assertJsonPath('data.screening.thresholds.hcl|3.near', [])->assertJsonPath('data.screening.updated_at', '2026-11-08T23:05:06.123456+00:00');
    }

    public function test_gas_snapshot_preserves_conditions_and_rejects_applying_stale_density(): void
    {
        $this->login('flow-gas-density');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $url = '/api/process-flows/'.$flow['id'];
        $substance = $this->substance('Газ', 29, -192, 800);
        $data = $this->patchJson($url, ['version' => 1, 'attributes' => ['phase' => 'gas', 'pressure' => 0, 'temperature' => 0],
            'composition' => [$this->compositionLine($substance, 100)], 'apply_calculation' => true])->assertOk()
            ->assertJsonPath('data.proposed_calculation.density_method', 'ideal_gas')->assertJsonPath('data.calculation_stale', false)->json('data');
        $signature = $data['proposed_calculation']['signature'];
        $snapshot = $data['accepted_calculation']['id'];
        $saved = $this->getJson($url.'/calculations/'.$snapshot)->assertOk()->assertJsonPath('data.inputs.conditions.pressure', 0)->assertJsonPath('data.inputs.conditions.temperature', 0)->json('data');
        $replayed = app(MixtureCalculator::class)->calculate($saved['inputs']['basis'], $saved['inputs']['components'], $saved['inputs']['conditions']);
        $this->assertSame($signature, $replayed['signature']);
        $density = $data['proposed_calculation']['properties']['density'];
        $this->patchJson($url, ['version' => 2, 'attributes' => ['temperature' => 100, 'density' => $density], 'expected_calculation_signature' => $signature])->assertConflict();
        $this->getJson($url)->assertJsonPath('data.attributes.temperature', 0)->assertJsonPath('data.attributes.density', null)->assertJsonPath('data.calculation_stale', false);
        $this->patchJson($url, ['version' => 2, 'attributes' => ['temperature' => 100]])->assertOk()->assertJsonPath('data.calculation_stale', true)
            ->assertJsonPath('data.accepted_calculation.properties.density', $density);
        $this->getJson($url.'/calculations/'.$snapshot)->assertOk()->assertJsonPath('data.inputs.conditions.temperature', 0)->assertJsonPath('data.result.properties.density', $density);
        $this->patchJson($url, ['version' => 3, 'attributes' => ['pressure' => null]])->assertOk()->assertJsonPath('data.proposed_calculation.properties.density', null);
    }

    public function test_mole_mass_properties_snapshots_staleness_and_zero_attributes(): void
    {
        $this->login('flow-mixture');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $url = '/api/process-flows/'.$flow['id'];
        $a = $this->substance('Компонент A', 10, 0, 1000);
        $b = $this->substance('Компонент B', 20, 100, 2000);
        $parts = [$this->compositionLine($a, 50), $this->compositionLine($b, 50)];
        $data = $this->patchJson($url, ['version' => 1, 'basis' => 'mole', 'attributes' => ['temperature' => '0', 'pressure' => 0, 'ph' => 0], 'composition' => $parts, 'apply_calculation' => true])->assertOk()->assertJsonPath('data.attributes.temperature', 0)->assertJsonPath('data.proposed_calculation.properties.molecular_weight', 15)->assertJsonPath('data.proposed_calculation.properties.boiling_point', 50)->assertJsonPath('data.proposed_calculation.properties.liquid_density', fn ($value) => abs($value - 1500) < 1e-8)->assertJsonPath('data.calculation_stale', false)->json('data');
        $this->assertEqualsWithDelta(100 / 3, $data['proposed_calculation']['rows'][0]['mass_percent'], 1e-8);
        $snapshot = $data['accepted_calculation']['id'];
        $saved = $this->getJson($url.'/calculations/'.$snapshot)->assertOk()->assertJsonPath('data.inputs.components.0.substance_values.mw', 10)->json('data');
        $replay = app(MixtureCalculator::class)->calculate($saved['inputs']['basis'], $saved['inputs']['components']);
        $this->assertSame($saved['signature'], $replay['signature']);
        $this->assertEqualsWithDelta($saved['result']['properties']['molecular_weight'], $replay['properties']['molecular_weight'], 1e-10);
        $this->patchJson('/api/substances/'.$a, ['version' => 1, 'values' => ['mw' => 15]])->assertOk();
        $this->patchJson($url, ['version' => 2, 'apply_calculation' => true, 'expected_calculation_signature' => $data['proposed_calculation']['signature']])->assertConflict();
        $this->patchJson($url, ['version' => 2, 'attributes' => ['molecular_weight' => 15], 'apply_calculation' => false, 'expected_calculation_signature' => $data['proposed_calculation']['signature']])->assertConflict();
        $this->getJson($url)->assertOk()->assertJsonPath('data.calculation_stale', true)->assertJsonPath('data.accepted_calculation.properties.molecular_weight', 15)->assertJsonPath('data.proposed_calculation.properties.molecular_weight', 17.5);
        $this->getJson($url.'/calculations/'.$snapshot)->assertJsonPath('data.inputs.components.0.substance_values.mw', 10);
        $this->patchJson($url, ['version' => 2, 'verification' => ['ph' => true]])->assertOk();
        $updated = $this->patchJson($url, ['version' => 3, 'apply_calculation' => true])->assertOk()->json('data');
        $this->assertNotSame($snapshot, $updated['accepted_calculation']['id']);
        $this->getJson($url.'/calculations')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson($url.'/events')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_partial_composition_ownership_basis_and_permissions(): void
    {
        $this->login('flow-partial');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $other = $this->flow($installation, 2);
        $url = '/api/process-flows/'.$flow['id'];
        $substance = $this->substance('Неизвестная MW', null, 0, 1000);
        $part = $this->compositionLine($substance, 60);
        $this->patchJson($url, ['version' => 1, 'composition' => [$part], 'apply_calculation' => true])->assertUnprocessable();
        $this->getJson($url)->assertJsonCount(0, 'data.composition')->assertJsonPath('data.version', 1);
        $this->patchJson($url, ['version' => 1, 'composition' => [$part]])->assertOk()->assertJsonPath('data.proposed_calculation.complete', false)->assertJsonPath('data.proposed_calculation.rows.0.mole_percent', null);
        $this->patchJson($url, ['version' => 2, 'basis' => 'mole'])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'basis' => 'mole', 'composition' => [$part]])->assertOk()->assertJsonPath('data.proposed_calculation.rows.0.mass_percent', null);
        $this->patchJson('/api/process-flows/'.$other['id'], ['version' => 1, 'composition' => [$part]])->assertNotFound();
        $this->patchJson($url, ['version' => 3, 'composition' => [$this->compositionLine($substance, 40)]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 3, 'composition' => [['id' => (string) Str::uuid(), 'role' => 'impurity', 'name' => 'Cl⁻, справочно', 'amount' => 0, 'unit' => 'mg_l']]])->assertOk()->assertJsonCount(2, 'data.composition');
        $this->login('flow-partial', 'member');
        $this->getJson($url)->assertOk();
        $this->patchJson($url, ['version' => 4, 'attributes' => ['ph' => 7]])->assertForbidden();
        $this->login('flow-other');
        $this->getJson($url)->assertNotFound();
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

    public function test_screening_values_comments_thresholds_versions_roles_and_tenant_isolation(): void
    {
        $this->login('flow-screening');
        $installation = $this->installation();
        $flow = $this->flow($installation);
        $url = '/api/process-flows/'.$flow['id'];
        $this->getJson($url)->assertOk()->assertJsonPath('data.screening', null)->assertJsonCount(21, 'data.screening_result.mechanisms');
        $patch = ['version' => 1, 'screening' => ['values' => ['hcl' => true, 'water' => true, 'ph' => '7,2'], 'comments' => ['hcl|3' => 'Измерено в водной фазе'], 'thresholds' => ['hcl|3' => ['threshold' => [7], 'near' => [0.3]]]]];
        $result = $this->patchJson($url, $patch)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.screening.values.ph', 7.2)->json('data');
        $this->assertSame('potential', collect($result['screening_result']['mechanisms'])->firstWhere('id', 'hcl')['status']);
        $this->getJson($url)->assertJsonPath('data.screening.comments.hcl|3', 'Измерено в водной фазе');
        $this->patchJson($url, $patch)->assertConflict();
        $this->patchJson($url, ['version' => 2, 'screening' => ['values' => ['ph' => 15]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'screening' => ['values' => ['unknown' => true]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'screening' => ['thresholds' => ['hcl|3' => ['threshold' => [7, 8]]]]])->assertUnprocessable();
        $this->patchJson($url, ['version' => 2, 'screening' => ['values' => ['hcl' => false, 'ph' => 0], 'thresholds' => ['hcl|3' => null]]])->assertOk()->assertJsonPath('data.screening.values.ph', 0)->assertJsonPath('data.screening.values.hcl', false);
        $history = $this->getJson($url.'/events')->assertOk()->json('data');
        $this->assertTrue(collect($history)->flatMap(fn ($event) => $event['changes'])->contains(fn ($change) => str_starts_with($change['key'], 'screening.')));
        $this->login('flow-screening', 'member');
        $this->patchJson($url, ['version' => 3, 'screening' => ['values' => ['hcl' => true]]])->assertForbidden();
        $this->login('flow-screening-other');
        $this->getJson($url)->assertNotFound();
    }
}
