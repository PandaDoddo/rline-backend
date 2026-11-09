<?php

namespace App\Tenancy;

use App\Models\Tenant;

class TenantDomains
{
    public function slug(string $host): ?string
    {
        $base = config('domains.base');
        if (! $base || ! str_ends_with($host, '.'.$base)) {
            return null;
        }
        $slug = substr($host, 0, -strlen('.'.$base));

        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $slug) ? $slug : null;
    }

    public function available()
    {
        return Tenant::query()->where('status', 'active')->where('database_status', 'ready');
    }

    public function url(string $slug): string
    {
        return 'https://'.$slug.'.'.config('domains.base');
    }
}
