<?php

namespace App\Documentation;

use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;
use Dedoc\Scramble\Support\Generator\SecurityScheme;

class SessionCookieSecurityStrategy extends MiddlewareAuthSecurityStrategy
{
    public function __construct()
    {
        parent::__construct(scheme: SecurityScheme::apiKey('cookie', config('session.cookie'))->as('sessionCookie')
            ->setDescription('HttpOnly cookie сеанса Sanctum. Браузер сохраняет её после входа и отправляет автоматически; вручную вставлять cookie не нужно.'));
    }
}
