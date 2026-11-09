<?php

return [
    'base' => strtolower((string) env('TENANT_BASE_DOMAIN', '')),
    'landing_origin' => env('LANDING_ORIGIN', ''),
];
