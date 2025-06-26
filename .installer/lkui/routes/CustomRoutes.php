<?php
include('Controllers/HostController.php');
include('Controllers/OrderController.php');
include('Controllers/TemplatesController.php');

$HostCtrl = new HostController($renderer, $conn);
$OrderCtrl = new OrderController($renderer, $conn);
$TemplatesCtrl = new TemplatesController($renderer, $conn);

return [
    // Homepage route
    ['GET', '/', ['HostController', 'showHomepage']],

    // Web view routes
    ['GET', '/hosts', ['HostController', 'showHosts']],
    ['GET', '/hosts/{id:\d+}', ['HostController', 'showHostDetail']],
    ['GET', '/orders', ['OrderController', 'showOrders']],
    ['GET', '/orders/{id:\d+}', ['OrderController', 'showOrderDetail']],

    // API routes - Host
    ['GET', '/lkui/api/hosts', ['HostController', 'listHosts']],
    ['POST', '/lkui/api/hosts', ['HostController', 'createHost']],
    ['GET', '/lkui/api/hosts/{id:\d+}', ['HostController', 'getHost']],

    // API routes - Order
    ['POST', '/lkui/api/orders', ['OrderController', 'createOrder']],
    ['GET', '/lkui/api/orders/{id:\d+}', ['OrderController', 'getOrder']],
    ['POST', '/lkui/api/orders/{id:\d+}/certificate', ['OrderController', 'updateOrder']],

    // API routes - Template
    ['GET', '/lkui/api/templates', ['TemplatesController', 'listTemplates']],
    ['GET', '/lkui/api/templates/{name}', ['TemplatesController', 'getTemplate']],
];
