<?php

$dbType = 'postgres';

$arrDbSettings = [
    'dsn' => '',
    'username' => 'lkui',
    'password' => 'lkui_secure_password_2024',
    'options' => null
];

switch($dbType) {
    case 'postgres':
        $arrDbSettings['dsn'] = 'pgsql:dbname=lkui;host=localhost;port=5432;';
        break;

    case 'mysql':
        $arrDbSettings['dsn'] = 'mysql:dbname=clouddb;host=127.0.0.1;';
        break;
    default:
}

/** Required settings - Do Not Modify **/
$arrRequiredSettings = [
    'name' => 'Bootstrap',
    'installer-name' => 'bootstrap',
    'views' => 'Views',
    'controllers' => 'Controllers',
    'requires' => ['date_module']
];

/** MY SETTINGS
    specify as 'key' => 'value' **/
$arrMySettings = [];

/*** Do Not Modify below this line **/
$arrSettings = $arrRequiredSettings;
$arrSettings['pdo'] = $arrDbSettings;
$arrSettings['options'] = $arrMySettings;

return $arrSettings;
