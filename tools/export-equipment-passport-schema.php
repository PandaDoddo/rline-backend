<?php

use Illuminate\Contracts\Console\Kernel;

// Development-time schema snapshot. Runtime writes never generate database columns.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$categories = ['Идентификатор' => 'equipment', 'Классификатор' => 'equipment_classifications', 'Рабочие параметры' => 'equipment_operating_parameters',
    'Конструкция' => 'equipment_constructions', 'Защитные покрытия' => 'equipment_protections', 'Технологическая среда' => 'equipment_media',
    'Скорость коррозии' => 'equipment_corrosion_settings', 'Диагностика' => 'equipment_diagnostic_settings', 'Местоположение' => 'equipment_constructions'];
$fields = [];
foreach (config('equipment.passport') as $key => $field) {
    $type = ($field['type'] ?? '') === 'date' ? 'date' : (($field['type'] ?? '') === 'number' ? (str_contains($field['rules'], 'integer') ? 'integer' : 'double') : 'text');
    $table = $key === 'medium' ? 'equipment_media' : $categories[$field['section']];
    $data = ['table' => $table, 'type' => $type, 'nullable' => str_contains($field['rules'], 'nullable'), 'options' => $field['options'] ?? []];
    foreach (['min', 'max'] as $bound) {
        if (preg_match('/(?:^|\|)'.$bound.':([0-9.-]+)/', $field['rules'], $match)) {
            $data[$bound] = (float) $match[1];
        }
    }
    $fields[$key] = $data;
}
$target = resource_path('schema/equipment-passport-v3.json');
if (! is_dir(dirname($target))) {
    mkdir(dirname($target), 0775, true);
}
file_put_contents($target, json_encode(['version' => 'equipment-passport-v3', 'fields' => $fields], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo count($fields)." typed passport fields exported.\n";
