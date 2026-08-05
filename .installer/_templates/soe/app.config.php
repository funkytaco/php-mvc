<?php

return [
    'installer-name' => '{{APP_NAME}}',
    'views' => 'Views',
    'controllers' => 'Controllers',
    'models' => 'Models',
    'pdo' => [
        'dsn' => 'pgsql:host={{APP_NAME}}-db;port=5432;dbname={{DB_NAME}}',
        'username' => '{{DB_USER}}',
        'password' => '{{DB_PASSWORD}}'
    ],
    'app_name' => 'Host Build — Order & Tracking Gateway',
    'base_url' => '/',
    'debug' => true,
    'has_eda' => {{HAS_EDA}},
    'eda_port' => {{EDA_PORT}},
    // Host-published port for this app (what a browser connects to).
    'app_port' => {{APP_PORT}},

    // APP_ENV gates the dev-only Helix progression endpoint (DESIGN-DD §4).
    // POST /api/dev/helix/advance exists ONLY when this is 'local' or 'demo'.
    'app_env' => 'demo',

    // Helix adapter selection (DESIGN-DD §3). 'mock' is the v1 in-app adapter;
    // 'http' would resolve credentials from helix_auth_ref inside HelixHttpClient
    // and is deliberately not implemented in v1.
    'helix' => [
        'driver' => 'mock',
        'base_url' => '',
        // Secret-store REFERENCE, never a literal credential (Golden Rule 4).
        'auth_ref' => '',
        'queue_map' => 'default'
    ],

    'keycloak' => [
        'enabled' => {{KEYCLOAK_ENABLED}},
        'realm' => '{{KEYCLOAK_REALM}}',
        'client_id' => '{{KEYCLOAK_CLIENT_ID}}',
        'client_secret' => '{{KEYCLOAK_CLIENT_SECRET}}',
        // auth_url is the INTERNAL container address (container-to-container).
        // host_port is what a browser uses to reach the admin console.
        'auth_url' => 'http://{{APP_NAME}}-keycloak:8080',
        'host_port' => {{KEYCLOAK_PORT}},
        'redirect_uri' => 'http://localhost:{{APP_PORT}}/auth/callback'
    ]
];
