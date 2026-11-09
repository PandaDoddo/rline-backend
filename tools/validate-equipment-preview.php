<?php

use App\Modules\Equipment\Application\EquipmentInputValidation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Validator;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$source = json_decode(file_get_contents(base_path('build/passport-import-preview.json')), true, flags: JSON_THROW_ON_ERROR);
$rules = array_filter(app(EquipmentInputValidation::class)->rules(), fn ($key) => $key === 'passport' || $key === 'passport_qualifiers' || str_starts_with($key, 'passport.') || str_starts_with($key, 'passport_qualifiers.'), ARRAY_FILTER_USE_KEY);
$errors = [];
foreach ($source['records'] as $record) {
    $validator = Validator::make(['passport' => $record['passport'], 'passport_qualifiers' => $record['passport_qualifiers'] ?: []], $rules);
    if ($validator->fails()) {
        $errors[] = ['equipment' => $record['legacy_id'], 'errors' => $validator->errors()->toArray()];
    }
}
file_put_contents(base_path('build/passport-validation-report.json'), json_encode(['equipment' => count($source['records']), 'invalid' => count($errors), 'errors' => $errors], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['equipment' => count($source['records']), 'invalid' => count($errors), 'examples' => array_slice($errors, 0, 5)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($errors ? 1 : 0);
