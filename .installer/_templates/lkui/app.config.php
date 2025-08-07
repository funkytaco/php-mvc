<?php

return [
    'installer-name' => '{{APP_NAME}}',
    'name' => 'License Key UI',
    'views' => 'Views',
    'controllers' => 'Controllers',
    'models' => 'Models',
    'requires' => ['date_module'],
    'pdo' => [
        'dsn' => 'pgsql:host={{APP_NAME}}-db;port=5432;dbname={{DB_NAME}}',
        'username' => '{{DB_USER}}',
        'password' => '{{DB_PASSWORD}}'
    ],
    'app_name' => '{{APP_NAME_UPPER}} - LKUI',
    'base_url' => '/',
    'debug' => true,
    'has_eda' => {{HAS_EDA}},
    'eda_port' => "{{EDA_PORT}}",
    'keycloak' => [
        'enabled' => '{{KEYCLOAK_ENABLED}}',
        'realm' => '{{KEYCLOAK_REALM}}',
        'client_id' => '{{KEYCLOAK_CLIENT_ID}}',
        'client_secret' => '{{KEYCLOAK_CLIENT_SECRET}}',
        'auth_url' => 'http://{{APP_NAME}}-keycloak:8080',
        'redirect_uri' => 'http://localhost:{{APP_PORT}}/auth/callback'
    ]
];