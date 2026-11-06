<?php

namespace App\Auth;

use App\Tenancy\TenantContext;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;

class LoginChallenge extends RedirectIfTwoFactorAuthenticatable
{
    protected function twoFactorChallengeResponse($request, $user)
    {
        $request->session()->regenerate();
        $request->session()->put('challenge_expires_at', now()->timestamp + config('authentication.challenge_seconds'));
        $request->session()->put('challenge_id', bin2hex(random_bytes(32)));
        $request->session()->put('challenge_tenant_id', app(TenantContext::class)->id());
        $request->session()->put('challenge_provider', $user->auth_provider);
        $request->session()->put('challenge_identity', $user->authenticationStamp());
        $request->merge(['remember' => false]);

        return parent::twoFactorChallengeResponse($request, $user);
    }
}
