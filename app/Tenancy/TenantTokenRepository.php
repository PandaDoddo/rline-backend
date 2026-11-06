<?php

namespace App\Tenancy;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;

class TenantTokenRepository extends DatabaseTokenRepository
{
    protected function getTable()
    {
        return parent::getTable()->where('tenant_id', app(TenantContext::class)->id());
    }

    protected function getPayload($email, $token)
    {
        return parent::getPayload($email, $token) + ['tenant_id' => app(TenantContext::class)->id()];
    }
}
