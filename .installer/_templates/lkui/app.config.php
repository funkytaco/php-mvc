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
    'eda' => [
        'enabled' => {{HAS_EDA}},
        'host' => '{{APP_NAME}}-eda',
        'port' => '{{EDA_PORT}}',
        'ssl_order_url' => 'http://{{APP_NAME}}-eda:5000/ssl-order',
        'ssl_expiry_url' => 'http://{{APP_NAME}}-eda:5001/ssl-expiry',
        'app_callback_url' => 'http://{{APP_NAME}}-app:8080/eda/api/ssl-expiry'
    ],
    'keycloak' => [
        'enabled' => '{{KEYCLOAK_ENABLED}}',
        'realm' => '{{KEYCLOAK_REALM}}',
        'client_id' => '{{KEYCLOAK_CLIENT_ID}}',
        'client_secret' => '{{KEYCLOAK_CLIENT_SECRET}}',
        'auth_url' => 'http://{{APP_NAME}}-keycloak:8080',
        'redirect_uri' => 'http://localhost:{{APP_PORT}}/auth/callback'
    ]
];