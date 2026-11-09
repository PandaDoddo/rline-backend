<?php

use App\Modules\Installations\Domain\InstallationPassportSchema;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$fields = [];
foreach (InstallationPassportSchema::FIELDS as $key => [$column, $type, $max]) {
    $fields[$key] = ['column' => $column, 'type' => $type, 'max' => $max, 'options' => InstallationPassportSchema::OPTIONS[$key] ?? []];
}
$target = resource_path('schema/installation-passport-v1.json');
$contents = json_encode(['version' => 'installation-passport-v1', 'fields' => $fields], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
if (is_file($target) && file_get_contents($target) !== $contents) {
    throw new RuntimeException('Released installation schema v1 cannot be overwritten; add a new version and migration.');
}
if (! is_file($target)) {
    file_put_contents($target, $contents);
}
echo count($fields)." installation fields exported.\n";
