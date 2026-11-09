<?php

use App\Modules\Equipment\Domain\ComponentDetailSchema;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
file_put_contents(base_path('build/component-geometry-schema.json'), json_encode(ComponentDetailSchema::metadata(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
echo "Component geometry metadata exported.\n";
