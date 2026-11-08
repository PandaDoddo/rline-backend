<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class AuthenticationBoundary
{
    public function handle(Request $request, Closure $next)
    {
        $context = app(TenantContext::class);
        if ($request->user() && $context->id() && ! $context->membership($request->user()->id)) {
            abort(403, 'Нет доступа к этой организации.');
        }
        if ($request->user() && $context->id() && ! ($context->provider($request->user()->auth_provider ?? '')['enabled'] ?? false)) {
            abort(403, 'Способ входа организации отключён.');
        }
        if ($request->user() && (! $request->user()->is_active || now()->timestamp - $request->session()->get('auth_started_at', now()->timestamp) > config('authentication.absolute_session_seconds'))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            abort(401, 'Сеанс завершён.');
        }
        if ($request->is('auth/login')) {
            $request->merge(['remember' => false]);
            $request->session()->forget(['login', 'challenge_id', 'challenge_expires_at']);
        }
        if ($request->user() && $request->is('api/*') && ! $request->is('api/health')
            && $context->requiresTotp($request->user()->auth_provider) && ! $request->user()->two_factor_confirmed_at) {
            abort(403, 'Сначала настройте двухфакторную аутентификацию.');
        }
        if ($request->is('auth/two-factor-challenge') && $request->isMethod('post')) {
            $id = $request->session()->get('challenge_id');
            if (! $id || now()->timestamp > $request->session()->get('challenge_expires_at', 0)
                || $request->session()->get('challenge_tenant_id') !== $context->id()
                || ! $context->membership((int) $request->session()->get('login.id'))
                || $request->session()->get('challenge_provider') !== $context->membership((int) $request->session()->get('login.id'))?->auth_provider
                || ! ($challengedUser = User::whereKey($request->session()->get('login.id'))->where('is_active', true)->first())
                || ! hash_equals($request->session()->get('challenge_identity', ''), $challengedUser->authenticationStamp())) {
                $request->session()->forget(['login', 'challenge_id', 'challenge_expires_at']);
                abort(410, 'Подтверждение истекло. Войдите заново.');
            }

            return Cache::lock('challenge-user:'.$request->session()->get('login.id'), 10)->block(5, function () use ($request, $next, $id) {
                if (Cache::has('challenge-used:'.$id)) {
                    abort(410, 'Подтверждение уже использовано.');
                }
                $response = $next($request);
                if ($request->user()) {
                    Cache::put('challenge-used:'.$id, true, 600);
                    $request->session()->forget(['challenge_id', 'challenge_expires_at']);
                }

                return $response;
            });
        }
        if ($request->user()?->auth_provider !== 'local' && ($request->is('auth/user/password') || $request->is('auth/user/profile-information'))) {
            throw ValidationException::withMessages(['provider' => 'Профиль и пароль управляются корпоративным каталогом.']);
        }

        return $next($request);
    }
}
