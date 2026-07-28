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
    'app_name' => '{{APP_NAME_UPPER}} Demo',
    'base_url' => '/',
    'debug' => true,
    'has_eda' => {{HAS_EDA}},
    'eda_port' => {{EDA_PORT}},
    // Host-published port for this app (what a browser connects to).
    'app_port' => {{APP_PORT}},
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
