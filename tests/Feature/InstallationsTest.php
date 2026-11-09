<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use App\Tenancy\TenantSchemaMigrator;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_installation_passport_is_typed_sparse_and_migrates_without_losing_values(): void
    {
        $tenant = $this->tenant('installation-relational-passport');
        $this->login($tenant);
        $parent = $this->production();
        $passport = ['full' => 'Полное наименование', 'purpose' => "Назначение\nВторая строка", 'year' => 'уточнить', 'capP' => 0, 'capF' => 2.48123456789, 'capU' => 'млрд м³/год', 'designer' => 'Проектировщик', 'licensor' => 'Лицензиар', 'opoReg' => '0001', 'opoCls' => 'IV', 'cat' => 'В1', 'atm' => 'мягкие', 'area' => 1234.123456789, 'stShift' => 0, 'stAll' => 100, 'head' => 'Руководитель', 'mech' => 'Механик'];
        $body = ['name' => 'Полный паспорт', 'parent_id' => $parent, 'passport' => $passport];
        $id = $this->postJson('/api/installations', $body)->assertCreated()->json('data.id');
        $empty = $this->postJson('/api/installations', ['name' => 'Пустой паспорт', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $this->postJson('/api/installations', ['name' => 'Известный ноль', 'parent_id' => $parent, 'passport' => ['area' => 0, 'stShift' => 0]])->assertCreated()->assertJsonPath('data.passport.area', 0);
        app(TenantContext::class)->set($tenant);
        try {
            $db = DB::connection('tenant');
            $this->assertFalse(Schema::connection('tenant')->hasColumn('installations', 'passport'));
            $this->assertSame(2, $db->table('installation_passports')->count());
            $this->assertFalse($db->table('installation_passports')->where('installation_id', $empty)->exists());
            $this->assertEquals(2.48123456789, $db->table('installation_passports')->where('installation_id', $id)->value('actual_capacity'));
            $this->assertSame('0001', $db->table('installation_passports')->where('installation_id', $id)->value('hazardous_facility_registration'));
            $path = 'database/tenant-migrations';
            $steps = $db->table('migrations')->where('migration', '>=', '2026_11_09_000046')->count();
            $this->assertSame(0, Artisan::call('migrate:rollback', ['--database' => 'tenant', '--path' => $path, '--step' => $steps, '--force' => true]));
            $this->assertEquals($passport, json_decode($db->table('installations')->where('id', $id)->value('passport'), true));
            $db->table('installations')->where('id', $id)->update(['passport' => json_encode(['unsupported_future_field' => 1])]);
            try {
                Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]);
                $this->fail('Unmapped installation data must not disappear during normalization.');
            } catch (\RuntimeException $exception) {
                $this->assertStringContainsString('Unmapped installation passport', $exception->getMessage());
            }
            $this->assertTrue(Schema::connection('tenant')->hasColumn('installations', 'passport'));
            $this->assertFalse(Schema::connection('tenant')->hasTable('installation_passports'));
            $db->table('installations')->where('id', $id)->update(['passport' => json_encode($passport)]);
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'tenant', '--path' => $path, '--force' => true]));
            try {
                $db->transaction(fn () => $db->table('installation_passports')->where('installation_id', $id)->update(['actual_capacity' => -1]));
                $this->fail('Physical input bounds must remain enforced in PostgreSQL.');
            } catch (QueryException $exception) {
                $this->assertSame('23514', $exception->getCode());
            }
        } finally {
            app(TenantContext::class)->set(null);
        }
        $this->getJson('/api/installations/'.$id)->assertOk()->assertJsonPath('data.passport', $passport);
        $this->patchJson('/api/installations/'.$id, array_replace($body, ['version' => 1, 'passport' => []]))->assertOk()->assertJsonPath('data.passport', []);
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

    public function test_archive_restore_preserves_links_and_only_restores_children_archived_with_installation(): void
    {
        $tenant = $this->tenant('installation-lifecycle');
        $this->login($tenant);
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $installation = $this->postJson('/api/installations', ['name' => 'Installation lifecycle', 'parent_id' => $parent])->assertCreated()->json('data');
        $id = $installation['id'];
        $equipment = $this->postJson('/api/equipment', ['installation_id' => $id, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Linked vessel']])->assertCreated()->json('data');
        $previouslyArchived = $this->postJson('/api/equipment', ['installation_id' => $id, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Previously archived vessel']])->assertCreated()->json('data');
        $this->patchJson('/api/equipment/'.$previouslyArchived['id'], ['installation_id' => $id, 'passport' => $previouslyArchived['passport'], 'version' => 1, 'status' => 'archived'])->assertOk();
        $flow = $this->postJson('/api/installations/'.$id.'/flow-nodes', ['structure_version' => 1, 'kind' => 'flow', 'category' => 'main', 'name' => 'Linked flow'])->assertCreated()->json('data');
        $url = '/api/installations/'.$id;
        $this->getJson($url.'/dependencies')->assertOk()->assertJsonPath('active_equipment', 1)->assertJsonPath('active_flow_nodes', 1);
        $this->deleteJson($url, ['version' => 1])->assertUnprocessable();
        $this->postJson($url.'/lifecycle', ['version' => 1, 'action' => 'archive'])->assertUnprocessable();
        $this->postJson($url.'/lifecycle', ['version' => 1, 'action' => 'archive', 'cascade' => true])->assertOk()->assertJsonPath('data.status', 'archived')->assertJsonPath('data.version', 2);
        $this->getJson('/api/installations')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/installations?status=archived')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/equipment/'.$equipment['id'])->assertJsonPath('data.status', 'archived');
        $this->getJson('/api/process-flows/'.$flow['id'])->assertJsonPath('data.status', 'archived');
        $this->postJson('/api/equipment', ['installation_id' => $id, 'profile_code' => 'ves_fnp_rtn', 'passport' => ['name' => 'Forbidden child']])->assertUnprocessable();
        $this->postJson('/api/installations/'.$id.'/flow-nodes', ['structure_version' => 3, 'kind' => 'flow', 'category' => 'main', 'name' => 'Forbidden flow'])->assertUnprocessable();
        $this->postJson($url.'/lifecycle', ['version' => 1, 'action' => 'restore', 'cascade' => true])->assertConflict();
        $this->postJson($url.'/lifecycle', ['version' => 2, 'action' => 'restore', 'cascade' => true])->assertOk()->assertJsonPath('data.status', 'active');
        $this->getJson('/api/equipment/'.$equipment['id'])->assertJsonPath('data.status', 'active');
        $this->getJson('/api/equipment/'.$previouslyArchived['id'])->assertJsonPath('data.status', 'archived');
        $this->getJson('/api/process-flows/'.$flow['id'])->assertJsonPath('data.status', 'active');
        $this->deleteJson('/api/facility-nodes/'.$parent, ['version' => 1])->assertUnprocessable();
        $this->postJson('/api/facility-nodes/'.$parent.'/lifecycle', ['version' => 1, 'action' => 'archive'])->assertUnprocessable();
        $this->login($tenant, 'member');
        $this->postJson($url.'/lifecycle', ['version' => 3, 'action' => 'archive', 'cascade' => true])->assertForbidden();
        $this->login($this->tenant('installation-lifecycle-other'));
        $this->postJson($url.'/lifecycle', ['version' => 3, 'action' => 'archive'])->assertNotFound();
    }

    public function test_empty_installation_deletion_and_parent_archive_restore_are_guarded(): void
    {
        $this->login($this->tenant('installation-empty-lifecycle'));
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $body = ['name' => 'Empty installation', 'parent_id' => $parent];
        $id = $this->postJson('/api/installations', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/installations/'.$id.'/lifecycle', ['version' => 1, 'action' => 'archive'])->assertOk();
        $this->postJson('/api/facility-nodes/'.$parent.'/lifecycle', ['version' => 1, 'action' => 'archive'])->assertOk();
        $this->postJson('/api/installations', $body)->assertUnprocessable();
        $this->postJson('/api/installations/'.$id.'/lifecycle', ['version' => 2, 'action' => 'restore'])->assertUnprocessable();
        $this->deleteJson('/api/facility-nodes/'.$parent, ['version' => 2])->assertUnprocessable();
        $this->postJson('/api/facility-nodes/'.$parent.'/lifecycle', ['version' => 2, 'action' => 'restore'])->assertOk();
        $this->postJson('/api/installations/'.$id.'/lifecycle', ['version' => 2, 'action' => 'restore'])->assertOk();
        $this->deleteJson('/api/installations/'.$id, ['version' => 1])->assertConflict();
        $this->deleteJson('/api/installations/'.$id, ['version' => 3])->assertNoContent();
        $this->getJson('/api/installations/'.$id)->assertNotFound();
        $this->deleteJson('/api/facility-nodes/'.$parent, ['version' => 3])->assertNoContent();
        app(TenantContext::class)->set(Tenant::where('slug', 'installation-empty-lifecycle')->first());
        $this->assertSame(2, DB::connection('tenant')->table('domain_events')->where('action', 'deleted')->whereRaw('"after" = \'null\'::jsonb')->count());
    }
}
