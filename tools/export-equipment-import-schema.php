<?php

use App\Modules\Equipment\Domain\OperatingScopeSchema;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$schema = ['version' => 'equipment-import-schema-v1', 'profiles' => config('equipment.profiles'), 'passport' => config('equipment.passport'),
    'operating_scopes' => OperatingScopeSchema::fields()];
$target = base_path('build/equipment-import-schema.json');
if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
echo count($schema['passport'])." declared import fields exported.\n";
