<?php

namespace Tests\Feature;

use App\Domain\MixtureCalculator;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
}
