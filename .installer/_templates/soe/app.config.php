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
    'app_name' => 'Order Gateway — {{APP_NAME_UPPER}}',
    'base_url' => '/',
    'debug' => true,
    'app_port' => {{APP_PORT}},
    'app_env' => 'demo'
];
