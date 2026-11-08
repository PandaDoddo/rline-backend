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

class EquipmentFlowContactsTest extends TestCase
{
    use DatabaseMigrations;

    private array $tenants = [];

    private array $body;

    private string $equipment;

    private string $installation;

    private Tenant $tenant;

    private function prepare(): void
    {
        $this->tenant = Tenant::create(['slug' => 'flow-contacts', 'name' => 'Flows', 'auth_config' => ['local' => ['enabled' => true]]]);
        $this->tenants[] = $this->tenant;
        app(TenantDatabaseManager::class)->provision($this->tenant);
        $this->login();
        $parent = null;
        foreach (['enterprise', 'site', 'production'] as $kind) {
            $parent = $this->postJson('/api/facility-nodes', ['kind' => $kind, 'name' => $kind, 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $this->installation = $this->postJson('/api/installations', ['name' => 'Flows', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $this->body = ['installation_id' => $this->installation, 'passport' => ['name' => 'Vessel'], 'version' => 1];
        $this->equipment = $this->postJson('/api/equipment', ['profile_code' => 'ves_fnp_rtn', 'installation_id' => $this->installation, 'passport' => $this->body['passport']])->assertCreated()->json('data.id');
    }

    private function login(string $role = 'owner'): void
    {
        $this->app['session']->flush();
        $user = User::factory()->create(['two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role, 'auth_provider' => 'local']);
        $this->actingAs($user)->withHeaders(['Origin' => 'http://localhost:5173', 'X-Tenant' => $this->tenant->slug]);
    }

    private function flow(string $category = 'main'): array
    {
        $version = $this->getJson('/api/installations/'.$this->installation.'/flow-nodes')->json('structure_version');

        return $this->postJson('/api/installations/'.$this->installation.'/flow-nodes', ['structure_version' => $version, 'kind' => 'flow', 'name' => $category, 'category' => $category])->assertCreated()->json('data');
    }

    private function contact(array $flow, string $scope = 'equipment', ?string $component = null, string $side = 'internal'): array
    {
        return ['id' => (string) Str::uuid(), 'scope' => $scope, 'component_id' => $component, 'side' => $side, 'mode' => 'assign', 'notes' => null, 'flows' => [['id' => (string) Str::uuid(), 'flow_id' => $flow['id'], 'share_percent' => null, 'notes' => null]]];
    }

    private function updateEquipment(array $data)
    {
        return $this->patchJson('/api/equipment/'.$this->equipment, array_replace($this->body, $data));
    }

    private function makeComponent(string $kind = 'shell', ?string $parent = null): array
    {
        return ['id' => (string) Str::uuid(), 'parent_id' => $parent, 'kind' => $parent ? 'element' : $kind, 'is_coil' => $kind === 'coil', 'name' => $kind, 'status' => 'active', 'position' => 0];
    }

    public function test_atomic_contacts_inheritance_coils_spaces_and_recheck(): void
    {
        $this->prepare();
        $main = $this->flow();
        $tube = $this->flow('auxiliary');
        $shell = $this->flow('repair');
        $root = $this->makeComponent();
        $element = $this->makeComponent('element', $root['id']);
        $coil = $this->makeComponent('coil');
        $bundle = $this->makeComponent('tube_bundle');
        $contact = $this->contact($main);
        $contact['verified'] = true;
        $tubeContact = $this->contact($tube, 'tube');
        $shellContact = $this->contact($shell, 'shell');
        $result = $this->updateEquipment(['structure' => [$root, $element, $coil, $bundle], 'flow_contacts' => [$contact, $tubeContact, $shellContact]])->assertOk()->assertJsonPath('data.version', 2);
        $effective = collect($result->json('data.flows.effective'));
        $pick = fn ($id, $side) => $effective->first(fn ($row) => $row['component_id'] === $id && $row['side'] === $side);
        $this->assertSame($contact['id'], $pick($element['id'], 'internal')['contact_id']);
        $this->assertTrue($pick($element['id'], 'internal')['inherited']);
        $this->assertSame('unknown', $pick($coil['id'], 'internal')['mode']);
        $this->assertSame($tubeContact['id'], $pick($bundle['id'], 'internal')['contact_id']);
        $this->assertSame($shellContact['id'], $pick($bundle['id'], 'external')['contact_id']);
        $this->assertSame(100, $pick($root['id'], 'internal')['main_share_percent']);
        $this->body['version'] = 2;
        $contact['notes'] = 'new';
        $this->updateEquipment(['flow_contacts' => [$contact]])->assertUnprocessable();
        $contact['unlock'] = true;
        $this->updateEquipment(['flow_contacts' => [$contact]])->assertOk()->assertJsonPath('data.version', 3);
        $this->body['version'] = 3;
        $none = $this->contact($main, 'component', $element['id']);
        $none['mode'] = 'none';
        $none['flows'] = [];
        $this->updateEquipment(['flow_contacts' => [$none]])->assertOk();
        $this->body['version'] = 4;
        $none['mode'] = 'inherit';
        $response = $this->updateEquipment(['flow_contacts' => [$none]])->assertOk();
        $restored = collect($response->json('data.flows.effective'))->first(fn ($row) => $row['component_id'] === $element['id'] && $row['side'] === 'internal');
        $this->assertSame($contact['id'], $restored['contact_id']);
        $this->updateEquipment(['flow_contacts' => []])->assertConflict();
        $this->getJson('/api/equipment')->assertOk()->assertJsonMissingPath('data.0.flows');
    }

    public function test_unknown_and_zero_shares_staleness_ownership_side_and_move_rollback(): void
    {
        $this->prepare();
        $main = $this->flow();
        $alternative = $this->flow('alternative');
        $outside = $this->flow('external');
        $root = $this->makeComponent();
        $contact = $this->contact($main);
        $contact['flows'][] = ['id' => (string) Str::uuid(), 'flow_id' => $alternative['id'], 'share_percent' => 0, 'notes' => null];
        $result = $this->updateEquipment(['structure' => [$root], 'flow_contacts' => [$contact]])->assertOk();
        $this->assertSame(100, $result->json('data.flows.effective.0.main_share_percent'));
        $this->body['version'] = 2;
        $contact['flows'][1]['share_percent'] = null;
        $this->updateEquipment(['flow_contacts' => [$contact]])->assertOk()->assertJsonPath('data.flows.effective.0.main_share_percent', null);
        $this->body['version'] = 3;
        $this->patchJson('/api/process-flows/'.$main['id'], ['version' => 1, 'attributes' => ['ph' => 0]])->assertOk();
        $stored = $this->getJson('/api/equipment/'.$this->equipment)->assertOk()->json('data.flows.contacts.0.flows');
        $this->assertTrue(collect($stored)->firstWhere('flow_id', $main['id'])['stale']);
        $bad = $this->contact($outside, 'component', $root['id']);
        $this->updateEquipment(['flow_contacts' => [$bad], 'passport' => ['name' => 'Must roll back']])->assertUnprocessable();
        $bad = $this->contact($main, 'component', (string) Str::uuid());
        $this->updateEquipment(['flow_contacts' => [$bad]])->assertUnprocessable();
        $copy = $this->contact($main);
        $this->updateEquipment(['flow_contacts' => [$copy]])->assertUnprocessable();
        $parent = $this->getJson('/api/installations/'.$this->installation)->json('data.parent_id');
        $other = $this->postJson('/api/installations', ['name' => 'Other', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $this->updateEquipment(['installation_id' => $other])->assertUnprocessable();
        $this->getJson('/api/equipment/'.$this->equipment)->assertJsonPath('data.passport.name', 'Vessel')->assertJsonPath('data.version', 3);
        $contact['mode'] = 'inherit';
        $contact['flows'] = [];
        $this->updateEquipment(['installation_id' => $other, 'flow_contacts' => [$contact]])->assertOk()->assertJsonPath('data.installation_id', $other);
        $this->login('member');
        $this->body['version'] = 4;
        $this->updateEquipment(['flow_contacts' => []])->assertForbidden();
    }

    public function test_foreign_ids_overbooked_shares_archived_flows_and_demount_keep_identity(): void
    {
        $this->prepare();
        $main = $this->flow();
        $a = $this->flow('alternative');
        $b = $this->flow('alternative');
        $root = $this->makeComponent();
        $contact = $this->contact($main);
        foreach ([$a, $b] as $flow) {
            $contact['flows'][] = ['id' => (string) Str::uuid(), 'flow_id' => $flow['id'], 'share_percent' => 60, 'notes' => null];
        }
        $this->updateEquipment(['structure' => [$root], 'flow_contacts' => [$contact]])->assertUnprocessable();
        $this->getJson('/api/equipment/'.$this->equipment)->assertJsonPath('data.version', 1)->assertJsonCount(0, 'data.components');
        $contact['flows'][2]['share_percent'] = 40;
        $this->updateEquipment(['structure' => [$root], 'flow_contacts' => [$contact]])->assertOk()->assertJsonPath('data.flows.effective.0.main_share_percent', 0);
        $this->body['version'] = 2;
        $other = $this->postJson('/api/equipment', ['profile_code' => 'ves_fnp_rtn', 'installation_id' => $this->installation, 'passport' => ['name' => 'Other']])->assertCreated()->json('data.id');
        $this->patchJson('/api/equipment/'.$other, array_replace($this->body, ['version' => 1, 'flow_contacts' => [$contact]]))->assertUnprocessable();
        $copy = $contact;
        $copy['id'] = (string) Str::uuid();
        $this->patchJson('/api/equipment/'.$other, array_replace($this->body, ['version' => 1, 'flow_contacts' => [$copy]]))->assertUnprocessable();
        $this->getJson('/api/equipment/'.$other)->assertJsonPath('data.version', 1)->assertJsonCount(0, 'data.flows.contacts');
        $version = $this->getJson('/api/installations/'.$this->installation.'/flow-nodes')->json('structure_version');
        $this->patchJson('/api/flow-nodes/'.$a['id'], ['version' => 1, 'structure_version' => $version, 'status' => 'archived'])->assertOk();
        $this->updateEquipment(['flow_contacts' => [$contact]])->assertOk();
        $this->body['version'] = 3;
        $contact['verified'] = true;
        $this->updateEquipment(['flow_contacts' => [$contact]])->assertUnprocessable();
        $new = $this->contact($a, 'component', $root['id']);
        $this->updateEquipment(['flow_contacts' => [$new]])->assertUnprocessable();
        $root['status'] = 'removed';
        $this->updateEquipment(['structure' => [$root]])->assertOk()->assertJsonPath('data.flows.effective.0.active', false);
        $this->body['version'] = 4;
        $root['status'] = 'active';
        $this->updateEquipment(['structure' => [$root]])->assertOk()->assertJsonPath('data.flows.effective.0.active', true)->assertJsonPath('data.flows.contacts.0.id', $contact['id']);
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
