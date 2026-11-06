<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\TwoFactorAuthenticationProvider;

class AtomicTotpProvider extends TwoFactorAuthenticationProvider
{
    public function verify($secret, $code)
    {
        if (! is_string($code) || ! preg_match('/^\d{6}$/D', $code)) {
            return false;
        }
        $this->engine->setWindow(1);
        $timestamp = $this->engine->verifyKeyNewer($secret, $code, 0);

        return $timestamp !== false && Cache::add('totp-used:'.hash('sha256', $secret.'|'.$timestamp), true, 120);
    }
}
