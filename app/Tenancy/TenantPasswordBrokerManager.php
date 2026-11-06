<?php

namespace App\Tenancy;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

class TenantPasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        $key = $this->app['config']['app.key'];
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        return new TenantTokenRepository($this->app['db']->connection($config['connection'] ?? null), $this->app['hash'], $config['table'], $key, ($config['expire'] ?? 60) * 60, $config['throttle'] ?? 0);
    }
}
