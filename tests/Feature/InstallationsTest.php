<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use App\Tenancy\TenantSchemaMigrator;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstallationsTest extends TestCase
{
    use DatabaseMigrations;

    private array $createdTenants = [];

    private function tenant(string $slug): Tenant
    {
        $tenant = Tenant::create(['slug' => $slug, 'name' => $slug, 'auth_config' => ['local' => ['enabled' => true]]]);
        $this->createdTenants[] = $tenant;
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

    private function production(): string
    {
        $enterprise = $this->postJson('/api/facility-nodes', ['name' => 'Предприятие', 'kind' => 'enterprise'])->assertCreated()->json('data.id');
        $site = $this->postJson('/api/facility-nodes', ['name' => 'Площадка', 'kind' => 'site', 'parent_id' => $enterprise])->assertCreated()->json('data.id');

        return $this->postJson('/api/facility-nodes', ['name' => 'Производство', 'kind' => 'production', 'parent_id' => $site])->assertCreated()->json('data.id');
    }

    public function test_passport_persists_versions_conflicts_search_and_audit(): void
    {
        $tenant = $this->tenant('installations-alpha');
        $this->login($tenant);
        $parent = $this->production();
        $body = ['name' => 'Компрессорная станция', 'parent_id' => $parent, 'code' => 'КС-1', 'passport' => ['purpose' => 'Компримирование', 'capP' => '2,48', 'capU' => 'млрд м³/год']];
        $response = $this->postJson('/api/installations', $body)->assertCreated()->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.passport.capP', 2.48)->assertJsonPath('data.path', 'Предприятие → Площадка → Производство');
        $id = $response->json('data.id');
        $this->getJson('/api/installations/'.$id)->assertOk()->assertJsonPath('data.passport.purpose', 'Компримирование');
        $this->getJson('/api/installations?q='.urlencode('компрессор'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/installations/'.$id, $body + ['version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/installations/'.$id, $body + ['version' => 1])->assertConflict();
        $this->getJson('/api/installations/'.$id.'/events')->assertOk()->assertJsonPath('total', 2);
        $this->postJson('/api/installations', $body)->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->patchJson('/api/installations/'.$id, ['name' => 'Правка', 'parent_id' => $parent, 'version' => 2, 'passport' => ['area' => -1]])->assertUnprocessable();
        $this->postJson('/api/installations', ['name' => 'Ошибка', 'parent_id' => $parent, 'passport' => ['capP' => 1]])->assertUnprocessable()->assertJsonValidationErrors('passport.capU');
        $this->assertFalse(Schema::connection('pgsql')->hasTable('installations'));
        app(TenantSchemaMigrator::class)->migrate($tenant);
        app(TenantSchemaMigrator::class)->migrate($tenant);
        $this->getJson('/api/installations/'.$id)->assertOk()->assertJsonPath('data.version', 2);
    }

    public function test_tenant_ids_and_parent_ids_are_isolated_and_member_is_read_only(): void
    {
        $alpha = $this->tenant('installations-alpha');
        $beta = $this->tenant('installations-beta');
        $owner = $this->login($alpha);
        $parent = $this->production();
        $body = ['name' => 'Установка Alpha', 'parent_id' => $parent];
        $id = $this->postJson('/api/installations', $body)->assertCreated()->json('data.id');
        TenantMembership::create(['tenant_id' => $beta->id, 'user_id' => $owner->id, 'role' => 'owner', 'auth_provider' => 'local']);
        $this->app['session']->flush();
        $this->withHeader('X-Tenant', $beta->slug);
        $this->getJson('/api/installations')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/installations/'.$id)->assertNotFound();
        $this->getJson('/api/installations/'.$id.'/events')->assertNotFound();
        $this->postJson('/api/installations', $body)->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson('/api/facility-nodes', ['name' => 'Чужая площадка', 'kind' => 'site', 'parent_id' => $parent])->assertUnprocessable();
        $this->withSession([]);
        $this->login($alpha, 'member');
        $this->getJson('/api/installations/'.$id)->assertOk();
        $this->postJson('/api/installations', $body)->assertForbidden();
        $this->patchJson('/api/installations/'.$id, $body + ['version' => 1])->assertForbidden();
        $this->postJson('/api/facility-nodes', ['name' => 'Чужая правка', 'kind' => 'enterprise'])->assertForbidden();
    }

    public function test_hierarchy_validation_and_updates_reject_cycles_and_stale_versions(): void
    {
        $tenant = $this->tenant('installations-alpha');
        $this->login($tenant);
        $parent = $this->production();
        $this->postJson('/api/facility-nodes', ['name' => 'Неверный уровень', 'kind' => 'enterprise', 'parent_id' => $parent])->assertUnprocessable();
        $this->postJson('/api/facility-nodes', ['name' => 'Нет родителя', 'kind' => 'site'])->assertUnprocessable();
        $this->patchJson('/api/facility-nodes/'.$parent, ['name' => 'Цикл', 'parent_id' => $parent, 'version' => 1])->assertUnprocessable();
        $this->patchJson('/api/facility-nodes/'.$parent, ['name' => 'Новое производство', 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson('/api/facility-nodes/'.$parent, ['name' => 'Старая правка', 'version' => 1])->assertConflict();
        $this->getJson('/api/facility-nodes?kind=production&per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Новое производство');
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->set(null);
        $central = DB::connection('pgsql');
        $this->assertSame('rline_test', $central->getDatabaseName());
        foreach ($this->createdTenants as $tenant) {
            $name = 'rline_t_'.$tenant->id;
            $central->unprepared('DROP DATABASE IF EXISTS "'.$name.'" WITH (FORCE)');
            $central->unprepared('DROP ROLE IF EXISTS "'.$name.'"');
        }
        parent::tearDown();
    }
}
