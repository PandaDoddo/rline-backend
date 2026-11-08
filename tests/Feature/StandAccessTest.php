<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Tests\TestCase;

class StandAccessTest extends TestCase
{
    private function tenants(): void
    {
        foreach (['alpha', 'beta'] as $slug) {
            Tenant::create(['slug' => $slug, 'name' => $slug, 'auth_config' => ['local' => ['enabled' => true]]]);
        }
        $hosts = config('stands.hosts');
        $hosts['testing.rline.test']['tenants'] = ['alpha'];
        $hosts['demo.rline.test']['tenants'] = [];
        config(['stands.hosts' => $hosts]);
    }

    public function test_allowed_tenant_can_select_and_read_providers(): void
    {
        $this->tenants();
        $this->postJson('http://testing.rline.test/auth/tenant', ['tenant' => 'alpha'])->assertOk()->assertJsonPath('tenant.slug', 'alpha');
        $this->getJson('http://testing.rline.test/auth/providers')->assertOk();
    }

    public function test_header_body_and_session_cannot_select_disallowed_tenant(): void
    {
        $this->tenants();
        $this->withHeader('X-Tenant', 'beta')->getJson('http://testing.rline.test/auth/providers')->assertForbidden();
        $this->withoutHeader('X-Tenant')->postJson('http://testing.rline.test/auth/tenant', ['tenant' => 'beta'])->assertForbidden();
        $this->withSession(['tenant_id' => Tenant::where('slug', 'beta')->value('id')])->getJson('http://testing.rline.test/auth/providers')->assertForbidden();
    }

    public function test_main_and_development_keep_existing_tenant_access(): void
    {
        $this->tenants();
        $this->withHeader('X-Tenant', 'beta')->getJson('http://main.rline.test/auth/providers')->assertOk();
        $this->getJson('http://localhost/auth/providers')->assertOk();
    }

    public function test_unknown_stand_and_empty_allowlist_fail_closed(): void
    {
        $this->tenants();
        $this->withHeader('X-Tenant', 'alpha')->getJson('http://other.rline.test/auth/providers')->assertForbidden();
        $this->getJson('http://demo.rline.test/auth/providers')->assertForbidden();
    }

    public function test_client_stand_header_does_not_override_host(): void
    {
        $this->tenants();
        $this->withHeaders(['X-Tenant' => 'beta', 'X-RLine-Stand' => 'main'])->getJson('http://testing.rline.test/auth/providers')->assertForbidden();
    }

    public function test_platform_admin_list_and_target_objects_respect_allowlist(): void
    {
        $this->tenants();
        $tenant = Tenant::where('slug', 'alpha')->firstOrFail();
        $beta = Tenant::where('slug', 'beta')->firstOrFail();
        $user = User::factory()->create(['is_platform_admin' => true, 'two_factor_confirmed_at' => now()])->refresh();
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner', 'auth_provider' => 'local']);
        $this->actingAs($user)->withHeaders(['X-Tenant' => 'alpha', 'Origin' => 'http://testing.rline.test']);
        $this->getJson('http://testing.rline.test/api/tenants')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'alpha');
        $this->getJson('http://testing.rline.test/api/tenants/'.$beta->id.'/authentication')->assertForbidden();
        $this->patchJson('http://testing.rline.test/api/tenants/'.$beta->id, ['name' => 'Changed'])->assertForbidden();
        $this->postJson('http://testing.rline.test/api/tenants', ['slug' => 'gamma', 'name' => 'Gamma'])->assertForbidden();
        $this->assertDatabaseMissing('tenants', ['slug' => 'gamma']);
        $this->assertSame('beta', $beta->fresh()->name);
    }

    public function test_stand_allowlist_does_not_grant_membership(): void
    {
        $this->tenants();
        $user = User::factory()->create(['password' => 'Local-Password123!']);
        $this->withHeader('X-Tenant', 'alpha')->postJson('http://testing.rline.test/auth/login', ['email' => $user->email, 'password' => 'Local-Password123!'])->assertUnprocessable();
    }
}
