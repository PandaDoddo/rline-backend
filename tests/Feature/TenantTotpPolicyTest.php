<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TenantTotpPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function user(bool $totp = false): User
    {
        return User::factory()->create(['password' => 'Strong-Test-Password1!', 'is_platform_admin' => true,
            'two_factor_secret' => $totp ? encrypt('JBSWY3DPEHPK3PXP') : null,
            'two_factor_confirmed_at' => $totp ? now() : null])->refresh();
    }

    private function passwordOnly(): void
    {
        $tenant = Tenant::where('slug', 'rline')->firstOrFail();
        $tenant->forceFill(['auth_config' => ['local' => ['enabled' => true, 'require_totp' => false]]])->save();
    }

    public function test_password_only_login_allows_api_without_totp_enrollment(): void
    {
        $this->passwordOnly();
        $user = $this->user();
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!'])->assertOk()->assertJsonPath('two_factor', false);
        $this->getJson('/auth/me')->assertOk()->assertJsonPath('user.two_factor_required', false)->assertJsonPath('user.two_factor_enabled', false);
        $this->getJson('/api/tenants')->assertOk();
    }

    public function test_wrong_password_is_rejected_in_password_only_mode(): void
    {
        $this->passwordOnly();
        $user = $this->user();
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])->assertUnprocessable();
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_default_policy_requires_enrollment_and_request_cannot_disable_it(): void
    {
        $user = $this->user();
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!', 'require_totp' => false])->assertOk();
        $this->getJson('/auth/me')->assertOk()->assertJsonPath('user.two_factor_required', true);
        $this->getJson('/api/tenants')->assertForbidden();
    }

    public function test_password_only_policy_does_not_erase_totp_or_weaken_other_tenants(): void
    {
        $this->passwordOnly();
        $user = $this->user(true);
        $beta = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'auth_config' => ['local' => ['enabled' => true]]]);
        TenantMembership::create(['tenant_id' => $beta->id, 'user_id' => $user->id, 'auth_provider' => 'local', 'role' => 'member']);
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!'])->assertOk()->assertJsonPath('two_factor', false);
        $this->postJson('/auth/tenant', ['tenant' => 'beta'])->assertOk();
        $this->withHeader('X-Tenant', 'beta')->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!', 'require_totp' => false])->assertOk()->assertJsonPath('two_factor', true);
        $this->assertSame('JBSWY3DPEHPK3PXP', decrypt($user->fresh()->two_factor_secret));
    }

    public function test_only_authorized_admin_can_change_policy_and_sessions_are_revoked(): void
    {
        $user = $this->user()->forceFill(['two_factor_confirmed_at' => now(), 'is_platform_admin' => false]);
        $user->save();
        $tenant = Tenant::where('slug', 'rline')->firstOrFail();
        $this->actingAs($user)->putJson('/api/tenants/'.$tenant->id.'/authentication', ['providers' => ['local' => ['require_totp' => false]]])->assertForbidden();
        TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $user->id)->update(['role' => 'owner']);
        $this->putJson('/api/tenants/'.$tenant->id.'/authentication', ['providers' => ['local' => ['require_totp' => false]]])->assertOk()->assertJsonPath('providers.local.require_totp', false);
        $this->getJson('/auth/me')->assertUnauthorized();
    }
}
