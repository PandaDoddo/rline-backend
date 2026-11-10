<?php

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Modules\Catalogs\Application\CatalogSnapshotUpdate;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;

Artisan::command('catalog:main-update {tenant} {actor} {--apply}', function () {
    $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
    $actor = (int) $this->argument('actor');
    abort_unless(TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $actor)->where('is_active', true)->whereIn('role', ['owner', 'admin'])->exists(), 403);
    $context = app(TenantContext::class);
    $previous = $context->tenant;
    try {
        $context->set($tenant);
        $snapshot = json_decode(file_get_contents(resource_path('catalogs/updates/main-430dcd4.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->line(json_encode(app(CatalogSnapshotUpdate::class)->run($snapshot, $actor, (bool) $this->option('apply')), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    } finally {
        $context->set($previous);
    }
})->purpose('Preview/apply main catalog delta; preserve local edits and server verification');
