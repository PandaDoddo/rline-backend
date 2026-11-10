<?php

use App\Modules\Catalogs\Application\SubstanceInputValidation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$records = json_decode(file_get_contents(base_path('build/substance-import-preview.json')), true, flags: JSON_THROW_ON_ERROR)['records'];
$errors = [];
foreach ($records as $index => $record) {
    try {
        app(SubstanceInputValidation::class)->validated(['version' => 1, 'values' => $record['values']], true);
    } catch (ValidationException $exception) {
        $errors[] = ['code' => $record['code'], 'errors' => $exception->errors()];
    }
}
file_put_contents(base_path('build/substance-import-validation.json'), json_encode(['records' => count($records), 'invalid' => count($errors), 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode(['records' => count($records), 'invalid' => count($errors), 'examples' => array_slice($errors, 0, 5)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
exit($errors ? 1 : 0);
