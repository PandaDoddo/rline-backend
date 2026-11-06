<?php

namespace App\Auth;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class AuthAudit
{
    public static function record(string $event, ?int $userId = null, ?string $provider = null, ?int $targetTenantId = null): void
    {
        DB::table('auth_events')->insert([
            'tenant_id' => app(TenantContext::class)->id(),
            'target_tenant_id' => $targetTenantId,
            'event' => $event, 'user_id' => $userId, 'provider' => $provider,
            'ip' => request()->ip(), 'user_agent' => mb_substr(request()->userAgent() ?? '', 0, 512),
            'created_at' => now(),
        ]);
    }
}
