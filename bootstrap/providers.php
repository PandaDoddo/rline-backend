<?php

use App\Providers\ApiDocumentationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    ApiDocumentationServiceProvider::class,
];
