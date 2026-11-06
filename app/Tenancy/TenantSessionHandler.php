<?php

namespace App\Tenancy;

use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;

class TenantSessionHandler extends DatabaseSessionHandler
{
    protected function getDefaultPayload($data)
    {
        $payload = parent::getDefaultPayload($data);
        $request = $this->container['request'];
        $tenantId = $request->hasSession() ? $request->session()->get('tenant_id') : null;
        $userId = $request->hasSession() ? $request->session()->get(Auth::guard('web')->getName()) : null;

        return array_replace($payload, ['tenant_id' => $tenantId, 'user_id' => $userId]);
    }
}
