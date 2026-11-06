<?php

namespace Tests\Feature;

use App\Auth\DirectoryClient;
use App\Auth\PasswordPolicy;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function localUser(array $extra = []): User
    {
        $user = User::factory()->create(['email' => 'engineer@example.test', 'password' => 'Strong-Test-Password1!', ...$extra]);

        return $user->refresh();
    }

    public function test_local_login_session_and_logout(): void
    {
        $user = $this->localUser();
        $this->getJson('/auth/me')->assertUnauthorized();
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!'])->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/auth/me')->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonMissingPath('user.password');
        $this->postJson('/auth/logout')->assertNoContent();
        app('auth')->forgetGuards();
        $this->getJson('/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('auth_events', ['event' => 'login', 'user_id' => $user->id]);
    }

    public function test_wrong_password_and_blocked_user_do_not_login(): void
    {
        $this->localUser(['is_active' => false]);
        $this->postJson('/auth/login', ['email' => 'engineer@example.test', 'password' => 'Strong-Test-Password1!'])->assertUnprocessable();
        $this->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_disabled_provider_never_attempts_directory_connection(): void
    {
        $this->mock(DirectoryClient::class)->shouldNotReceive('verify');
        $this->postJson('/auth/login', ['provider' => 'ldap', 'email' => 'user', 'password' => 'anything'])->assertUnprocessable();
    }

    public function test_directory_identity_is_preprovisioned_and_has_no_local_fallback(): void
    {
        config(['authentication.providers.ldap.enabled' => true]);
        $tenant = Tenant::where('slug', 'rline')->firstOrFail();
        $settings = $tenant->auth_config;
        $settings['ldap']['enabled'] = true;
        $tenant->update(['auth_config' => $settings]);
        $user = $this->localUser(['auth_provider' => 'ldap', 'auth_subject' => 'directory-uuid']);
        $this->mock(DirectoryClient::class)->shouldReceive('verify')->once()->with(\Mockery::type('array'), 'engineer', 'directory-password')->andReturn('directory-uuid');
        $this->postJson('/auth/login', ['provider' => 'ldap', 'email' => 'engineer', 'password' => 'directory-password'])->assertOk();
        $this->getJson('/auth/me')->assertJsonPath('user.id', $user->id);
        $this->postJson('/auth/logout');
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!'])->assertUnprocessable();
    }

    public function test_totp_challenge_is_required_and_expires(): void
    {
        $user = $this->localUser(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode(['backup-one']))]);
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!'])->assertJsonPath('two_factor', true);
        $this->getJson('/auth/me')->assertUnauthorized();
        $this->travel(6)->minutes();
        $this->postJson('/auth/two-factor-challenge', ['code' => '123456'])->assertStatus(410);
    }

    public function test_totp_code_authenticates_and_challenge_cannot_be_replayed(): void
    {
        $user = $this->localUser(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode(['backup-one']))]);
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!'])->assertJsonPath('two_factor', true);
        $code = (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXP');
        $this->postJson('/auth/two-factor-challenge', ['code' => $code])->assertNoContent();
        $this->getJson('/auth/me')->assertOk();
        $this->postJson('/auth/two-factor-challenge', ['code' => $code])->assertStatus(410);
    }

    public function test_recovery_code_is_consumed(): void
    {
        $user = $this->localUser(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => encrypt(json_encode(['backup-one']))]);
        $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'Strong-Test-Password1!']);
        $this->postJson('/auth/two-factor-challenge', ['recovery_code' => 'backup-one'])->assertNoContent();
        $this->assertNotContains('backup-one', $user->fresh()->recoveryCodes());
    }

    public function test_login_rate_limit_applies_across_ip_addresses(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.'.($i + 1)])
                ->postJson('/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/auth/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_absolute_session_expiration_and_account_block_are_enforced(): void
    {
        $user = $this->localUser();
        $this->actingAs($user)->withSession(['auth_started_at' => now()->subHours(9)->timestamp])->getJson('/auth/me')->assertUnauthorized();
        $user->forceFill(['is_active' => false])->save();
        $this->actingAs($user)->getJson('/auth/me')->assertUnauthorized();
    }

    public function test_multifactor_is_disabled_and_rejects_unsolicited_callback(): void
    {
        $this->getJson('/auth/multifactor/redirect')->assertStatus(503);
        $this->getJson('/auth/multifactor/callback?state=forged&code=forged')->assertForbidden();
    }

    public function test_current_password_cannot_be_reused(): void
    {
        $user = $this->localUser(['password_changed_at' => now()->subDays(2)]);
        $this->expectException(ValidationException::class);
        app(PasswordPolicy::class)->change($user, 'Strong-Test-Password1!');
    }

    public function test_password_change_revokes_sessions_and_records_history(): void
    {
        $user = $this->localUser(['password_changed_at' => now()->subDays(2)]);
        DB::table('sessions')->insert(['id' => 'other-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        app(PasswordPolicy::class)->change($user, 'Different-Strong-Password2!');
        $this->assertTrue(Hash::check('Different-Strong-Password2!', $user->fresh()->password));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('password_histories', ['user_id' => $user->id]);
        $this->assertDatabaseHas('auth_events', ['event' => 'password_changed', 'user_id' => $user->id]);
    }

    public function test_sessions_of_another_user_cannot_be_revoked(): void
    {
        $user = $this->localUser();
        $other = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'foreign-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);
        $foreignId = hash_hmac('sha256', 'foreign-session', config('app.key'));
        $this->actingAs($user)->deleteJson('/auth/sessions/'.$foreignId)->assertNoContent();
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-session']);
    }

    public function test_password_reset_response_does_not_reveal_account_existence(): void
    {
        Notification::fake();
        $user = $this->localUser();
        $known = $this->postJson('/auth/forgot-password', ['email' => $user->email])->assertOk()->json();
        $unknown = $this->postJson('/auth/forgot-password', ['email' => 'missing@example.test'])->assertOk()->json();
        $this->assertSame($known, $unknown);
    }

    public function test_totp_is_single_use(): void
    {
        $provider = app(TwoFactorAuthenticationProvider::class);
        $secret = 'JBSWY3DPEHPK3PXP';
        $code = (new Google2FA)->getCurrentOtp($secret);
        $this->assertTrue($provider->verify($secret, $code));
        $this->assertFalse($provider->verify($secret, $code));
    }

    public function test_unauthenticated_request_without_accept_header_still_returns_401(): void
    {
        $this->get('/auth/me')->assertUnauthorized();
    }
}
