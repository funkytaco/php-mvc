<?php

    $dbType = 'postgres';

    $arrDbSettings = [
    'dsn' => '',
    'username' => 'luki',
    'password' => 'lkui_secure_password_2024',
    'options' => null
    ];

    switch($dbType) {
        case 'postgres':
        $arrDbSettings['dsn'] = 'pgsql:dbname=lkui;host=db;';
        break;

        case 'mysql':
        $arrDbSettings['dsn'] = 'mysql:dbname=lkui;host=db;';
        break;
        default:
    }

    /** Required settings - Do Not Modify **/
    $arrRequiredSettings = [
        'name' => 'Rest API',
        'installer-name' => 'rest-api',
        'views' => 'Views',
        'controllers' => 'Controllers',
        'requires' => ['date_module']
    ];

    /** MY SETTINGS. specify as 'key' => 'value' **/
    $arrMySettings = [];


    /*** Do Not Modify below this line **/
    $arrSettings = $arrRequiredSettings;
    $arrSettings['pdo'] = $arrDbSettings;
    $arrSettings['options'] = $arrMySettings;

    return $arrSettings;