<?php

use App\Models\Tenant;
use App\Modules\Equipment\Models\ComponentType;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$tenant = Tenant::where('slug', $argv[1] ?? 'rline')->firstOrFail();
app(TenantContext::class)->set($tenant);
try {
    $rows = ComponentType::orderBy('position')->get()->toArray();
    file_put_contents(base_path('build/component-types.json'), json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    echo count($rows)." component types exported.\n";
} finally {
    app(TenantContext::class)->set(null);
}
