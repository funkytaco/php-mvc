<?php

include('Controllers/IndexController.php');
include('Controllers/ContactController.php');
include('Controllers/AboutController.php');

// Create module dependencies
$mod_date = $injector->make('Main\Modules\Date_Module');

// Instantiate controllers with dependencies
$IndexCtrl = new IndexController($renderer, $conn, $mod_date);
$ContactCtrl = new ContactController($renderer, $conn, $mod_date);
$AboutCtrl = new AboutController($renderer, $conn, $mod_date);

// FastRoute compatible routes
return [
    ['GET', '/', [$IndexCtrl, 'get']],
    ['GET', '/about', [$AboutCtrl, 'get']],
    ['GET', '/contact', [$ContactCtrl, 'get']],
];
