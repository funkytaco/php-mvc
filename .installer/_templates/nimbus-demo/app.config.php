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
    'has_eda' => false,
    'eda_port' => {{EDA_PORT}}
];