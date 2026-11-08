<?php

namespace Tests\Feature;

use App\Domain\DamageMechanismBaseline;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

class DamageMechanismsTest extends TestCase
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

    public function test_baseline_summary_filters_and_related_mechanisms_are_complete(): void
    {
        $this->login($this->tenant('damage-read'));
        $this->getJson('/api/damage-mechanisms')->assertOk()->assertJsonPath('meta.total', 71)->assertJsonCount(50, 'data')->assertJsonMissingPath('data.0.values');
        $id = DamageMechanismBaseline::id('e885');
        $card = $this->getJson('/api/damage-mechanisms/'.$id)->assertOk()->assertJsonPath('data.legacy_code', 'e885')->assertJsonPath('data.values.paragraph', '3.1')->assertJsonCount(3, 'data.related')->json('data');
        $this->assertNotEmpty($card['values']['ru_d']);
        $this->assertNotEmpty($card['values']['en_d']);
        $this->assertArrayNotHasKey('original_d', $card['values']);
        $this->assertContains('fss', $card['values']['material_groups']);
        $this->assertContains('fss', $card['values']['added_material_groups']);
        $this->getJson('/api/damage-mechanisms?q=885&materials[]=ss')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/damage-mechanisms?q=885&materials[]=ni')->assertOk()->assertJsonPath('meta.total', 0);
        $corrosion = $this->getJson('/api/damage-mechanisms?mode=corrosion&per_page=100')->assertOk()->json('data');
        $this->assertNotEmpty($corrosion);
        foreach ($corrosion as $row) {
            $this->assertSame('коррозия', $row['kind']);
        }
        foreach ($card['related'] as $related) {
            $this->getJson('/api/damage-mechanisms/'.$related['id'])->assertOk();
        }
    }

    public function test_tenant_text_and_material_overrides_have_sources_checks_conflicts_reset_and_audit(): void
    {
        $alpha = $this->tenant('damage-alpha');
        $beta = $this->tenant('damage-beta');
        $user = $this->login($alpha);
        $id = DamageMechanismBaseline::id('e885');
        $base = $this->getJson('/api/damage-mechanisms/'.$id)->assertOk()->json('data');
        $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 1, 'values' => ['ru_d' => 'Тестовое рабочее описание', 'original_d' => 'Текст пользователя для теста', 'material_groups' => ['ss']], 'verification' => ['material_groups' => true], 'sources' => ['ru_d' => ['citation' => 'Локальный протокол', 'conditions' => 'Проверка']]])->assertOk()->assertJsonPath('data.values.ru_d', 'Тестовое рабочее описание')->assertJsonPath('data.checks.material_groups.by', $user->id)->assertJsonPath('data.version', 2);
        $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 1, 'values' => ['ru_d' => 'Старая версия']])->assertConflict();
        app(TenantContext::class)->set($alpha);
        app(DamageMechanismBaseline::class)->seed();
        app(TenantContext::class)->set(null);
        $this->getJson('/api/damage-mechanisms/'.$id)->assertOk()->assertJsonPath('data.values.ru_d', 'Тестовое рабочее описание')->assertJsonPath('data.base_values.ru_d', $base['values']['ru_d']);
        $this->getJson('/api/damage-mechanisms/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.before');
        $this->login($beta);
        $this->getJson('/api/damage-mechanisms/'.$id)->assertOk()->assertJsonPath('data.values.ru_d', $base['values']['ru_d'])->assertJsonMissingPath('data.values.original_d')->assertJsonMissingPath('data.checks.material_groups');
        $this->login($alpha);
        $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 2, 'reset_fields' => ['ru_d', 'material_groups', 'original_d']])->assertOk()->assertJsonPath('data.values.ru_d', $base['values']['ru_d'])->assertJsonMissingPath('data.checks.material_groups')->assertJsonMissingPath('data.overrides.original_d');
    }

    public function test_readonly_identifiers_and_relations_permissions_and_archive(): void
    {
        $tenant = $this->tenant('damage-permissions');
        $this->login($tenant);
        $id = DamageMechanismBaseline::id('e885');
        foreach ([['paragraph' => '3.99'], ['related_ids' => []], ['material_groups' => ['invented']], ['material_groups' => ['ss', 'ss']], ['kind' => 'invented'], ['name' => null]] as $values) {
            $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 1, 'values' => $values])->assertUnprocessable();
        }
        $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 1, 'status' => 'archived'])->assertOk();
        $this->getJson('/api/damage-mechanisms?status=archived')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 2, 'status' => 'active'])->assertOk();
        $this->login($tenant, 'member');
        $this->getJson('/api/damage-mechanisms/'.$id)->assertOk();
        $this->patchJson('/api/damage-mechanisms/'.$id, ['version' => 3, 'values' => ['ru_d' => 'Запрещено']])->assertForbidden();
    }

    public function test_catalog_history_shows_latest_200_entries_without_deleting_audit(): void
    {
        $actor = $this->login($this->tenant('damage-history'));
        $id = DamageMechanismBaseline::id('e885');
        $rows = [];
        for ($index = 0; $index < 205; $index++) {
            $rows[] = ['entity_type' => 'damage_mechanism', 'entity_id' => $id, 'action' => 'updated', 'actor_id' => $actor->id, 'before' => null, 'after' => json_encode(['base_values' => ['name' => 'Механизм'], 'overrides' => [], 'checks' => [], 'sources' => [], 'status' => 'active'], JSON_THROW_ON_ERROR)];
        }
        $rows[204]['actor_id'] = User::factory()->create()->id;
        app(TenantContext::class)->set($this->tenants[0]);
        DB::connection('tenant')->table('domain_events')->insert($rows);
        $this->assertSame(205, DB::connection('tenant')->table('domain_events')->count());
        app(TenantContext::class)->set(null);
        $this->getJson('/api/damage-mechanisms/'.$id.'/events')->assertOk()->assertJsonPath('meta.total', 200)->assertJsonPath('meta.last_page', 7)->assertJsonPath('data.0.id', 205)->assertJsonPath('data.0.actor_name', null)->assertJsonPath('data.1.actor_name', $actor->name);
        $this->getJson('/api/damage-mechanisms/'.$id.'/events?page=7')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('data.19.id', 6);
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
