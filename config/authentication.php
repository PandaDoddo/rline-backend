<?php

return [
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'challenge_seconds' => 300,
    'absolute_session_seconds' => 28800,
    'max_sessions' => 3,
    'providers' => [
        'assistent' => ['label' => 'Ассистент (Астерит)', 'enabled' => false, 'status' => 'protocol_not_confirmed'],
        'bearpass' => ['label' => 'BearPass', 'enabled' => false, 'status' => 'not_an_identity_provider'],
        'local' => ['label' => 'Локальная учётная запись', 'enabled' => env('AUTH_LOCAL_ENABLED', true), 'require_totp' => true],
        'ldap' => [
            'label' => 'LDAP', 'enabled' => env('AUTH_LDAP_ENABLED', false),
            'uri' => env('LDAP_URI'), 'base_dn' => env('LDAP_BASE_DN'),
            'bind_dn' => env('LDAP_BIND_DN'), 'bind_password' => env('LDAP_BIND_PASSWORD'),
            'start_tls' => env('LDAP_START_TLS', true), 'login_attribute' => 'uid',
        ],
        'ad' => [
            'label' => 'Active Directory', 'enabled' => env('AUTH_AD_ENABLED', false),
            'uri' => env('AD_URI'), 'base_dn' => env('AD_BASE_DN'),
            'bind_dn' => env('AD_BIND_DN'), 'bind_password' => env('AD_BIND_PASSWORD'),
            'start_tls' => env('AD_START_TLS', true), 'login_attribute' => 'sAMAccountName',
        ],
        'multifactor' => [
            'label' => 'Multifactor', 'enabled' => env('AUTH_MULTIFACTOR_ENABLED', false),
            'issuer' => env('MULTIFACTOR_ISSUER'),
            'discovery_url' => env('MULTIFACTOR_DISCOVERY_URL', 'https://api.multifactor.ru/.well-known/openid-configuration'),
            'client_id' => env('MULTIFACTOR_CLIENT_ID'), 'client_secret' => env('MULTIFACTOR_CLIENT_SECRET'),
            'callback' => env('MULTIFACTOR_CALLBACK', 'http://localhost:8080/auth/multifactor/callback'),
        ],
    ],
];
