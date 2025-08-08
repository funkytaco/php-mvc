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
    'has_eda' => '{{HAS_EDA}}',
    'eda_port' => "{{EDA_PORT}}",
    'eda' => [
        'enabled' => '{{HAS_EDA}}',
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
    ],
    
    // Generator templates - defines which template files to generate and where
    'generator_templates' => [
        'eda/templates/ssl-order-playbook-certbot.yml.mustache' => [
            'output_path' => 'eda/playbooks/ssl-order-playbook-certbot.yml',
            'variables' => [
                'API_PREFIX' => 'lkui',
                'ORDER_UPDATE_ENDPOINT' => '/lkui/api/orders',
                'CERTIFICATE_UPDATE_ENDPOINT' => '/lkui/api/orders'
            ]
        ],
        'eda/templates/ssl-order-playbook-letsencrypt.yml.mustache' => [
            'output_path' => 'eda/playbooks/ssl-order-playbook-letsencrypt.yml',
            'variables' => [
                'API_PREFIX' => 'lkui',
                'ORDER_UPDATE_ENDPOINT' => '/lkui/api/orders',
                'CERTIFICATE_UPDATE_ENDPOINT' => '/lkui/api/orders'
            ]
        ],
        'eda/templates/ssl-order-playbook-selfsigned.yml.mustache' => [
            'output_path' => 'eda/playbooks/ssl-order-playbook-selfsigned.yml',
            'variables' => [
                'API_PREFIX' => 'lkui',
                'ORDER_UPDATE_ENDPOINT' => '/lkui/api/orders',
                'CERTIFICATE_UPDATE_ENDPOINT' => '/lkui/api/orders'
            ]
        ]
    ]
];