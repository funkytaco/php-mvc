<?php
require_once('Controllers/HostController.php');
require_once('Controllers/OrderController.php');
require_once('Controllers/TemplatesController.php');

// Create module dependencies
$mod_date = $this->injector->make('Main\Modules\Date_Module');

$HostCtrl = new HostController($this->renderer, $this->conn, $mod_date);
$OrderCtrl = new OrderController($this->renderer, $this->conn, $mod_date, $HostCtrl);
$TemplatesCtrl = new TemplatesController($this->renderer, $this->conn);

return [
    //Homepage route
    ['GET', '/', [$HostCtrl, 'get']],

    // Web view routes
    ['GET', '/hosts', [$HostCtrl, 'showHosts']],
    ['GET', '/hosts/{hostId:\d+}', [$HostCtrl, 'showHostDetail']],
    ['GET', '/order', [$OrderCtrl, 'showCreateOrder']], //?hostId={hostId:\d+}
    ['GET', '/orders', [$OrderCtrl, 'showOrders']],
    ['GET', '/orders/{orderId:\d+}', [$OrderCtrl, 'showOrderDetail']],

    // API routes - Host
    ['GET', '/lkui/api/hosts', [$HostCtrl, 'listHosts']],
    ['POST', '/lkui/api/hosts', [$HostCtrl, 'createHost']],
    ['GET', '/lkui/api/hosts/{hostId:\d+}', [$HostCtrl, 'getHost']],

    // API routes - Order
    ['POST', '/lkui/api/orders', [$OrderCtrl, 'createOrder']],
    ['GET', '/lkui/api/orders/{orderId:\d+}', [$OrderCtrl, 'getOrder']],
    ['POST', '/lkui/api/orders/{orderId:\d+}/certificate', [$OrderCtrl, 'updateOrder']],

    // API routes - Template
    ['GET', '/lkui/api/templates', [$TemplatesCtrl, 'listTemplates']],
    ['GET', '/lkui/api/templates/{templateName}', [$TemplatesCtrl, 'getTemplate']],
];