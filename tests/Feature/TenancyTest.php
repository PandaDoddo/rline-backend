<?php

namespace Tests\Feature;

use App\Auth\DirectoryClient;
use App\Jobs\ProvisionTenantDatabase;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantJobContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenancyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    private function tenant(string $slug, array $providers = []): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => $slug, 'auth_config' => $providers ?: ['local' => ['enabled' => true]]]);
    }

    private function member(Tenant $tenant, User $user, array $extra = []): TenantMembership
    {
        return TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'auth_provider' => 'local', 'role' => 'member', ...$extra]);
    }

    private function user(): User
    {
        return User::factory()->create(['password' => 'Tenant-Password123!', 'two_factor_confirmed_at' => now()])->refresh();
    }

    public function test_membership_is_required_even_with_correct_local_password(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = $this->user();
        $this->member($a, $user);
        $this->withHeader('X-Tenant', $b->slug)->postJson('/auth/login', ['email' => $user->email, 'password' => 'Tenant-Password123!'])->assertUnprocessable();
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_session_cannot_be_relabelled_with_another_tenant_header(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = $this->user();
        $this->member($a, $user);
        $this->member($b, $user);
        $this->withHeader('X-Tenant', 'alpha')->postJson('/auth/login', ['email' => $user->email, 'password' => 'Tenant-Password123!'])->assertOk();
        $this->getJson('/auth/me')->assertJsonPath('user.tenant.slug', 'alpha');
        $this->withHeader('X-Tenant', 'beta')->getJson('/auth/me')->assertStatus(409);
    }

    public function test_same_global_user_can_use_different_providers_per_tenant(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta', ['ldap' => ['enabled' => true, 'uri' => 'ldaps://beta.example.test', 'bind_password' => 'beta-secret']]);
        $user = $this->user();
        $this->member($a, $user);
        $this->member($b, $user, ['auth_provider' => 'ldap', 'auth_subject' => 'beta-uuid']);
        $this->mock(DirectoryClient::class)->shouldReceive('verify')->once()->withArgs(fn ($config, $login, $password) => $config['uri'] === 'ldaps://beta.example.test' && $config['bind_password'] === 'beta-secret' && $login === 'directory-user')->andReturn('beta-uuid');
        $this->withHeader('X-Tenant', 'beta')->postJson('/auth/login', ['provider' => 'local', 'email' => $user->email, 'password' => 'Tenant-Password123!'])->assertUnprocessable();
        $this->postJson('/auth/login', ['provider' => 'ldap', 'email' => 'directory-user', 'password' => 'directory-pass'])->assertOk();
        $this->getJson('/auth/me')->assertJsonPath('user.id', $user->id)->assertJsonPath('user.provider', 'ldap');
    }

    public function test_reset_token_is_not_accepted_in_another_tenant(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = $this->user();
        $this->member($a, $user);
        $this->member($b, $user);
        app(TenantContext::class)->set($a);
        $token = Password::broker()->createToken($user);
        app(TenantContext::class)->set(null);
        $this->withHeader('X-Tenant', 'beta')->postJson('/auth/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'New-Tenant-Password2!', 'password_confirmation' => 'New-Tenant-Password2!'])->assertUnprocessable();
    }

    public function test_provider_secrets_are_encrypted_and_never_public(): void
    {
        $tenant = $this->tenant('alpha', ['local' => ['enabled' => true], 'ldap' => ['enabled' => false, 'bind_password' => 'secret-alpha']]);
        $user = $this->user();
        $this->member($tenant, $user, ['role' => 'owner']);
        $this->assertStringNotContainsString('secret-alpha', DB::table('tenants')->where('id', $tenant->id)->value('auth_config'));
        $this->withHeader('X-Tenant', 'alpha')->getJson('/auth/providers')->assertOk()->assertDontSee('secret-alpha');
        $this->actingAs($user)->getJson('/api/tenants/'.$tenant->id.'/authentication')->assertOk()->assertJsonPath('providers.ldap.has_bind_password', true)->assertJsonMissingPath('providers.ldap.bind_password');
    }

    public function test_tenant_owner_cannot_read_another_tenants_settings_or_create_tenants(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = $this->user();
        $this->member($a, $user, ['role' => 'owner']);
        $this->withHeader('X-Tenant', 'alpha')->actingAs($user)->getJson('/api/tenants/'.$b->id.'/authentication')->assertForbidden();
        $this->postJson('/api/tenants', ['slug' => 'gamma', 'name' => 'Gamma'])->assertForbidden();
    }

    public function test_platform_admin_creates_tenant_without_exposing_configuration(): void
    {
        Queue::fake();
        $tenant = $this->tenant('alpha');
        $user = $this->user()->forceFill(['is_platform_admin' => true]);
        $user->save();
        $this->member($tenant, $user);
        $this->withHeader('X-Tenant', 'alpha')->actingAs($user)->postJson('/api/tenants', ['slug' => 'gamma', 'name' => 'Gamma'])
            ->assertCreated()->assertJsonPath('slug', 'gamma')->assertJsonMissingPath('auth_config')->assertJsonMissingPath('database_config');
        $this->assertDatabaseHas('user_tenants', ['user_id' => $user->id, 'role' => 'owner']);
        Queue::assertPushed(ProvisionTenantDatabase::class);
    }

    public function test_setting_change_invalidates_bound_sessions(): void
    {
        $tenant = $this->tenant('alpha');
        $user = $this->user();
        $this->member($tenant, $user, ['role' => 'owner']);
        $this->withHeader('X-Tenant', 'alpha')->actingAs($user)->getJson('/auth/me')->assertOk();
        $this->putJson('/api/tenants/'.$tenant->id.'/authentication', ['providers' => ['local' => ['enabled' => true]]])->assertOk();
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_suspended_and_unknown_tenants_cannot_sign_in(): void
    {
        $tenant = $this->tenant('alpha');
        $tenant->update(['status' => 'suspended']);
        $this->withHeader('X-Tenant', 'alpha')->getJson('/auth/providers')->assertNotFound();
        $this->withHeader('X-Tenant', 'missing')->getJson('/auth/providers')->assertNotFound();
    }

    public function test_sessions_from_another_tenant_are_not_listed_or_revoked(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = $this->user();
        $this->member($a, $user);
        $this->member($b, $user);
        DB::table('sessions')->insert(['id' => 'beta-session', 'tenant_id' => $b->id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->withHeader('X-Tenant', 'alpha')->actingAs($user)->getJson('/auth/sessions')->assertExactJson([]);
        $this->deleteJson('/auth/sessions/others')->assertNoContent();
        $this->assertDatabaseHas('sessions', ['id' => 'beta-session']);
    }

    public function test_explicit_switch_clears_authentication_and_pending_challenge(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $this->withSession(['tenant_id' => $a->id, 'login.id' => 999, 'challenge_id' => 'old'])
            ->withHeader('X-Tenant', 'beta')->postJson('/auth/tenant', ['tenant' => 'beta'])->assertOk()->assertJsonPath('tenant.slug', 'beta');
        $this->assertFalse(session()->has('login.id'));
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_reset_token_works_only_in_issuing_tenant_and_only_once(): void
    {
        $tenant = $this->tenant('alpha');
        $user = $this->user();
        $this->member($tenant, $user);
        app(TenantContext::class)->set($tenant);
        $token = Password::broker()->createToken($user);
        app(TenantContext::class)->set(null);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'Replacement-Password123!', 'password_confirmation' => 'Replacement-Password123!'];
        $this->withHeader('X-Tenant', 'alpha')->postJson('/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check($payload['password'], $user->fresh()->password));
        $this->postJson('/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_environment_directory_secrets_do_not_fall_through_to_new_tenants(): void
    {
        config(['authentication.providers.ldap.bind_password' => 'global-secret']);
        $tenant = $this->tenant('alpha', ['ldap' => ['enabled' => false]]);
        $this->assertNull(app(TenantContext::class)->provider('ldap', $tenant)['bind_password']);
    }

    public function test_pending_mfa_challenge_cannot_move_to_another_tenant(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = User::factory()->create(['password' => 'Tenant-Password123!', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->refresh();
        $this->member($a, $user);
        $this->member($b, $user);
        $this->withHeader('X-Tenant', 'alpha')->postJson('/auth/login', ['email' => $user->email, 'password' => 'Tenant-Password123!'])->assertJsonPath('two_factor', true);
        $this->withHeader('X-Tenant', 'beta')->postJson('/auth/two-factor-challenge', ['code' => '123456'])->assertStatus(409);
        $this->withHeader('X-Tenant', 'alpha')->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_job_context_is_cleared_after_a_failure(): void
    {
        $tenant = $this->tenant('alpha');
        $tenant->update(['database_status' => 'ready', 'database_config' => ['database' => 'unused', 'username' => 'unused', 'password' => 'unused']]);
        try {
            (new TenantJobContext($tenant->id))->handle(new \stdClass, function () use ($tenant) {
                $this->assertSame($tenant->id, app(TenantContext::class)->id());
                throw new \RuntimeException('test failure');
            });
            $this->fail('Expected job failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('test failure', $exception->getMessage());
        }
        $this->assertNull(app(TenantContext::class)->id());
        $this->assertNull(config('database.connections.tenant'));
    }

    public function test_password_change_invalidates_an_unfinished_mfa_login(): void
    {
        $tenant = $this->tenant('alpha');
        $user = User::factory()->create(['password' => 'Tenant-Password123!', 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->refresh();
        $this->member($tenant, $user);
        $this->withHeader('X-Tenant', 'alpha')->postJson('/auth/login', ['email' => $user->email, 'password' => 'Tenant-Password123!'])->assertJsonPath('two_factor', true);
        $user->forceFill(['password' => 'Replacement-Password123!'])->save();
        $this->postJson('/auth/two-factor-challenge', ['code' => '123456'])->assertStatus(410);
    }

    public function test_suspend_and_resume_do_not_restore_previous_sessions(): void
    {
        $a = $this->tenant('alpha');
        $b = $this->tenant('beta');
        $user = $this->user();
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->member($a, $user);
        $this->member($b, $user);
        $this->withHeader('X-Tenant', 'alpha')->actingAs($user)->patchJson('/api/tenants/'.$b->id, ['status' => 'suspended'])->assertOk();
        $this->withHeader('X-Tenant', 'beta')->getJson('/auth/providers')->assertNotFound();
        $this->withHeader('X-Tenant', 'alpha')->patchJson('/api/tenants/'.$b->id, ['status' => 'active'])->assertOk();
        $this->assertSame(3, $b->fresh()->auth_version);
        $this->withSession(['tenant_id' => $b->id, 'tenant_version' => 1])->withHeader('X-Tenant', 'beta')->getJson('/auth/me')->assertUnauthorized();
    }
}
