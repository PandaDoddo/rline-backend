<?php

namespace Tests\Feature;

use App\Modules\Catalogs\Infrastructure\SubstanceBaseline;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

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
