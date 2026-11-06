<?php

use App\Http\Middleware\AuthenticationBoundary;
use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'passwords' => 'users',
    'username' => 'email', 'email' => 'email', 'lowercase_usernames' => true,
    'home' => '/', 'prefix' => 'auth', 'domain' => null,
    'middleware' => ['web', AuthenticationBoundary::class],
    'views' => false,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'features' => [
        Features::resetPasswords(), Features::updateProfileInformation(), Features::updatePasswords(),
        Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true, 'window' => 1]),
    ],
];
