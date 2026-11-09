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

class ManagementAssessmentTest extends TestCase
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

    public function test_answers_are_typed_versioned_tenant_scoped_and_complete_scores_are_reproducible(): void
    {
        $this->login('management-alpha');
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['name' => $kind, 'kind' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $id = $this->postJson('/api/installations', ['name' => 'Анкета управления', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $url = '/api/installations/'.$id.'/management';
        $schema = $this->getJson('/api/rbi/management/schema')->assertOk()->json();
        $this->getJson($url)->assertOk()->assertJsonPath('data.assessment.ready', false)->assertJsonPath('data.assessment.total', 122)->assertJsonPath('data.assessment.score', null);
        $body = ['version' => 1, 'schema_version' => $schema['version'], 'answers' => [['question_id' => '1.1', 'state' => 'answered', 'value' => true, 'comment' => 'Положение утверждено']]];
        $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.answers.0.value', true)->assertJsonPath('data.assessment.answered', 1);
        $this->patchJson($url, $body)->assertConflict();
        $body['version'] = 2;
        $body['answers'][0]['state'] = 'not_applicable';
        $body['answers'][0]['comment'] = null;
        $this->patchJson($url, $body)->assertUnprocessable();
        $this->getJson($url)->assertJsonPath('data.version', 2)->assertJsonPath('data.answers.0.comment', 'Положение утверждено');
        $fixture = json_decode(file_get_contents(__DIR__.'/../Fixtures/management-reference.json'), true, flags: JSON_THROW_ON_ERROR)['cases'][6];
        $body['answers'] = $fixture['answers'];
        $result = $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.version', 3)->assertJsonPath('data.assessment.ready', true);
        $this->assertEqualsWithDelta($fixture['expected']['pscore'], $result->json('data.assessment.score'), 1e-12);
        app(TenantContext::class)->set(Tenant::where('slug', 'management-alpha')->firstOrFail());
        try {
            $this->assertGreaterThan(0, DB::connection('tenant')->table('installation_management_selections')->count());
            $this->assertIsBool(DB::connection('tenant')->table('installation_management_answers')->where('question_id', '1.1')->value('boolean_value'));
        } finally {
            app(TenantContext::class)->set(null);
        }
        $body['version'] = 3;
        $body['answers'] = [['question_id' => '1.1', 'state' => 'answered', 'value' => 'true', 'comment' => null]];
        $this->patchJson($url, $body)->assertUnprocessable();
        $body['answers'] = [['question_id' => '1.1', 'state' => 'unanswered', 'value' => null, 'comment' => 'Уточняем документ']];
        $this->patchJson($url, $body)->assertOk()->assertJsonPath('data.assessment.ready', false)->assertJsonPath('data.assessment.score', null);
        $this->getJson($url.'/events')->assertOk()->assertJsonPath('meta.total',3)->assertJsonPath('data.0.changes.0.to','нет ответа');
        $this->login('management-alpha', 'member');
        $this->getJson($url)->assertOk();
        $this->patchJson($url, $body)->assertForbidden();
        $this->login('management-other');
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
