<?php

return [
    'installer-name' => 'test-app',
    'views' => 'Views',
    'controllers' => 'Controllers',
    'models' => 'Models',
    'pdo' => [
        'dsn' => 'pgsql:host=test-app-db;port=5432;dbname=test-app_db',
        'username' => 'test-app_user',
        'password' => '70e5d8562672b835fe0840203e2c5f40'
    ],
    'app_name' => 'TEST-APP Demo',
    'base_url' => '/',
    'debug' => true
];