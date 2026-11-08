<?php

use App\Documentation\SessionCookieSecurityStrategy;
use App\Documentation\TenantModelTypes;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\OpenApiVersion;

return [
    'api_path' => ['include' => ['api', 'auth', 'sanctum/csrf-cookie']],
    'api_domain' => null,
    'export_path' => 'build/openapi.json',
    'openapi_version' => OpenApiVersion::V3_1,
    'info' => ['version' => '0.1.0', 'description' => file_get_contents(resource_path('docs/api-introduction.md'))],
    'ui' => ['title' => 'R-Line API'],
    'servers' => ['Текущий сервер' => '/'],
    'cache' => ['key' => 'scramble.openapi', 'store' => 'file'],
    'dev_tools' => ['enabled' => false],
    'middleware' => ['web', RestrictedDocsAccess::class],
    'renderer' => 'elements',
    'renderers' => ['elements' => [
        'view' => 'docs.api', 'theme' => 'light', 'hideTryIt' => false,
        'hideSchemas' => false, 'logo' => '', 'tryItCredentialsPolicy' => 'include',
        'layout' => 'responsive', 'router' => 'hash',
    ]],
    'security_strategy' => SessionCookieSecurityStrategy::class,
    'extensions' => [TenantModelTypes::class],
];
