<?php
require __DIR__.'/../vendor/autoload.php';
$application=require __DIR__.'/../bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$schema=['version'=>App\Modules\Equipment\Domain\OperatingScopeSchema::VERSION,'fields'=>array_values(array_map(fn($field)=>array_diff_key($field,['rules'=>true]),App\Modules\Equipment\Domain\OperatingScopeSchema::fields()))];
file_put_contents(__DIR__.'/../build/operating-scope-schema.json',json_encode($schema,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL);
echo 'Exported '.count($schema['fields']).' operating scope fields.'.PHP_EOL;
