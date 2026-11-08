<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;

class TenantDatabaseReady
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(app(TenantContext::class)->tenant?->database_status === 'ready', 503, 'База организации ещё не подготовлена.');

        return $next($request);
    }
}
