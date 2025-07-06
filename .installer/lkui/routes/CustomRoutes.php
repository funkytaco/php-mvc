<?php

require_once('Controllers/HostController.php');
require_once('Controllers/OrderController.php');
require_once('Controllers/TemplatesController.php');
require_once('Controllers/ExpiryController.php');

return function ($injector, $renderer, $conn) {
    $mod_date = $injector->make('Main\Modules\Date_Module');

    $HostCtrl = new HostController($renderer, $conn, $mod_date);
    $OrderCtrl = new OrderController($renderer, $conn, $mod_date, $HostCtrl);
    $TemplatesCtrl = new TemplatesController($renderer, $conn);
    $ExpiryCtrl = new ExpiryController($renderer, $conn, $mod_date);

    return [
        //Homepage route
        ['GET', '/', [$HostCtrl, 'get']],

        // Web view routes
        ['GET', '/hosts', [$HostCtrl, 'showHosts']],
        ['GET', '/hosts/{host_id:\d+}', [$HostCtrl, 'showHostDetail']],
        ['GET', '/order', [$OrderCtrl, 'showCreateOrder']],
        ['GET', '/orders', [$OrderCtrl, 'showOrders']],
        ['GET', '/orders/{orderId:\d+}', [$OrderCtrl, 'showOrderDetail']],

        //Templates routes
        ['GET', '/templates', [$TemplatesCtrl, 'showTemplates']],

        //Expiry routes
        ['GET', '/expiry', [$ExpiryCtrl, 'get']],

        // API routes - Host
        ['GET', '/lkui/api/hosts', [$HostCtrl, 'listHosts']],
        ['POST', '/lkui/api/hosts', [$HostCtrl, 'createHost']],
        ['GET', '/lkui/api/hosts/{host_id:\d+}', [$HostCtrl, 'getHost']],

        // API routes - Order
        ['POST', '/lkui/api/orders', [$OrderCtrl, 'createOrder']],
        ['GET', '/lkui/api/orders/{orderId:\d+}', [$OrderCtrl, 'getOrder']],
        ['POST', '/lkui/api/orders/{orderId:\d+}/certificate', [$OrderCtrl, 'updateOrder']],

        // API routes - Template
        ['GET', '/lkui/api/templates', [$TemplatesCtrl, 'listTemplates']],
        ['GET', '/lkui/api/templates/{templateName}', [$TemplatesCtrl, 'getTemplate']],

        //EDA API routes
        ['POST', '/eda/api/ssl-order', [$OrderCtrl, 'submitSslOrder']]
        //['POST', '/lkui/api/ssl-order', [$OrderCtrl, 'forwardSslOrder']],
    ];
};