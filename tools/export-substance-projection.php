<?php

use App\Modules\Catalogs\Infrastructure\SubstanceCardProjection;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$target = resource_path('schemas/substance-cards-v2.sql');
$sql = SubstanceCardProjection::definition().";\n";
if (file_exists($target) && file_get_contents($target) !== $sql) {
    throw new RuntimeException('Frozen projection changed; add a new schema version instead.');
}
file_put_contents($target, $sql);
echo "Substance projection v2 verified.\n";
