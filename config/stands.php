<?php

return [
    'hosts' => [
        'main.rline.test' => ['name' => 'main', 'tenants' => ['*']],
        'testing.rline.test' => ['name' => 'testing', 'tenants' => array_values(array_filter(array_map('trim', explode(',', env('STAND_TESTING_TENANTS', 'rline')))))],
        'demo.rline.test' => ['name' => 'demo', 'tenants' => array_values(array_filter(array_map('trim', explode(',', env('STAND_DEMO_TENANTS', 'rline')))))],
    ],
];
