<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Auth\AtomicTotpProvider;
use App\Auth\AuthAudit;
use App\Auth\AuthPluginManager;
use App\Auth\LoginChallenge;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDomains;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuthPluginManager::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->singleton(TwoFactorAuthenticationProvider::class, AtomicTotpProvider::class);
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols());
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(LoginChallenge::class);
        ResetPassword::createUrlUsing(fn ($user, $token) => (config('domains.base')
            ? app(TenantDomains::class)->url(app(TenantContext::class)->tenant->slug)
            : config('authentication.frontend_url')).'/?'.http_build_query(['reset_token' => $token, 'email' => $user->email, 'tenant' => app(TenantContext::class)->tenant?->slug]));
        Fortify::confirmPasswordsUsing(function ($user, $password) {
            if ($user->auth_provider === 'multifactor') {
                return false;
            }
            $username = $user->auth_provider === 'local' ? $user->email : request()->session()->get('auth_login');
            if (! is_string($username)) {
                return false;
            }
            $candidate = app(AuthPluginManager::class)->resolve($user->auth_provider)->authenticate($username, $password ?? '');

            return $candidate?->id === $user->id;
        });
        Fortify::authenticateUsing(function (Request $request) {
            if ($request->attributes->has('authenticated_candidate')) {
                return $request->attributes->get('authenticated_candidate');
            }
            $data = $request->validate(['provider' => 'sometimes|string|max:32', 'email' => 'required|string|max:255', 'password' => 'required|string|max:1024']);
            $provider = $data['provider'] ?? 'local';
            try {
                $user = app(AuthPluginManager::class)->resolve($provider)->authenticate($data['email'], $data['password']);
            } catch (\RuntimeException $exception) {
                AuthAudit::record('provider_unavailable', null, $provider);
                abort(503, 'Сервис входа временно недоступен.');
            }
            if (! $user) {
                AuthAudit::record('login_failed', null, $provider);

                return null;
            }
            if ($provider === 'local' && $user->password_changed_at?->lt(now()->subDays(90))) {
                throw ValidationException::withMessages(['email' => 'Срок действия пароля истёк. Восстановите доступ.']);
            }
            $request->attributes->set('authenticated_candidate', $user);
            $request->session()->put('auth_login', $data['email']);

            return $user;
        });
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinutes(15, 5)->by('tenant:'.app(TenantContext::class)->id().':user:'.hash('sha256', mb_strtolower((string) $request->input('email')))),
            Limit::perMinutes(15, 20)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinutes(5, 5)->by($request->session()->getId()));
        RateLimiter::for('auth-actions', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        Event::listen(Login::class, function (Login $event) {
            request()->session()->put('tenant_id', app(TenantContext::class)->id());
            request()->session()->put('auth_started_at', now()->timestamp);
            request()->session()->forget('login');
            DB::table('sessions')->where('user_id', $event->user->id)
                ->where('tenant_id', app(TenantContext::class)->id())
                ->orderByDesc('last_activity')->skip(config('authentication.max_sessions') - 1)->take(1000)
                ->get(['id'])->each(fn ($session) => DB::table('sessions')->where('id', $session->id)->delete());
            AuthAudit::record('login', $event->user->id, $event->user->auth_provider);
        });
        Event::listen(Logout::class, fn (Logout $event) => AuthAudit::record('logout', $event->user?->id, $event->user?->auth_provider));
        foreach ([
            TwoFactorAuthenticationFailed::class => 'two_factor_failed',
            TwoFactorAuthenticationConfirmed::class => 'two_factor_confirmed',
            TwoFactorAuthenticationDisabled::class => 'two_factor_disabled',
        ] as $eventClass => $name) {
            Event::listen($eventClass, fn ($event) => AuthAudit::record($name, $event->user->id, $event->user->auth_provider));
        }
    }
}
