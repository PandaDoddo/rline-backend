<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class StandAccess
{
    public function tenants(Request $request): array
    {
        $host = $request->getHost();
        // Host names contain dots, so read the host map without dot notation.
        $stand = config('stands.hosts', [])[$host] ?? null;
        abort_if($stand === null && str_ends_with($host, '.rline.test'), 403, 'Неизвестный стенд.');

        return $stand['tenants'] ?? ['*'];
    }

    public function ensureAllowed(Request $request, string $slug): void
    {
        $tenants = $this->tenants($request);
        abort_unless(in_array('*', $tenants, true) || in_array($slug, $tenants, true), 403, 'Организация недоступна на этом стенде.');
    }

    public function scope(Request $request, Builder $query): Builder
    {
        $tenants = $this->tenants($request);

        return in_array('*', $tenants, true) ? $query : $query->whereIn('slug', $tenants);
    }
}
