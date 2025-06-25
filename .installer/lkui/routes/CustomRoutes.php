<?php
include('Controllers/HostController.php');
include('Controllers/OrderController.php');
include('Controllers/TemplatesController.php');

$HostCtrl = new HostController($renderer, $conn);
$OrderCtrl = new OrderController($renderer, $conn);
$TemplatesCtrl = new TemplatesController($renderer, $conn);

return [
    // Host routes
    ['GET', '/lkui/api/hosts', [$HostCtrl, 'listHosts']],
    ['POST', '/lkui/api/hosts', [$HostCtrl, 'createHost']],
    ['GET', '/lkui/api/hosts/{id:\d+}', [$HostCtrl, 'getHost']],
    
    // Order routes
    ['POST', '/lkui/api/orders', [$OrderCtrl, 'createOrder']],
    ['GET', '/lkui/api/orders/{id:\d+}', [$OrderCtrl, 'getOrder']],
    ['POST', '/lkui/api/orders/{id:\d+}/certificate', [$OrderCtrl, 'updateOrder']],
    
    // Template routes
    ['GET', '/lkui/api/templates', [$TemplatesCtrl, 'listTemplates']],
    ['GET', '/lkui/api/templates/{name}', [$TemplatesCtrl, 'getTemplate']]
];
