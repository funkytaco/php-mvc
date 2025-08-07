<?php

require_once('Controllers/HostController.php');
require_once('Controllers/OrderController.php');
require_once('Controllers/TemplatesController.php');
require_once('Controllers/ExpiryController.php');
require_once('Models/TemplateModel.php');
require_once('Models/HostModel.php');
require_once('Models/OrderModel.php');

return function ($injector, $renderer, $conn) {
    $mod_date = $injector->make('Main\Modules\Date_Module');

    // Create model instances
    $templateModel = new \Models\TemplateModel($conn);
    $hostModel = new \Models\HostModel($conn);
    $orderModel = new \Models\OrderModel($conn);

    // Create controller instances with model dependencies
    $HostCtrl = new HostController($renderer, $conn, $mod_date, $templateModel);
    $OrderCtrl = new OrderController($renderer, $conn, $mod_date, $HostCtrl);
    $TemplatesCtrl = new TemplatesController($renderer, $conn, $templateModel);
    $ExpiryCtrl = new ExpiryController($renderer, $conn, $mod_date);

    return [
        //Homepage route
        ['GET', '/', [$HostCtrl, 'get']],

        // Web view routes
        ['GET', '/hosts', [$HostCtrl, 'showHosts']],
        ['GET', '/hosts/{host_id:\d+}', [$HostCtrl, 'showHostDetail']],
        ['GET', '/order', [$OrderCtrl, 'showCreateOrder']],
        ['GET', '/orders', [$OrderCtrl, 'showOrders']],
        ['GET', '/orders/{order_id:\d+}', [$OrderCtrl, 'showOrderDetail']],

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
        ['GET', '/lkui/api/orders/{order_id:\d+}', [$OrderCtrl, 'getOrder']],
        ['POST', '/lkui/api/orders/{order_id:\d+}', [$OrderCtrl, 'updateOrder']],
        ['POST', '/lkui/api/orders/{order_id:\d+}/certificate', [$OrderCtrl, 'updateOrderCertificate']],
        ['GET', '/lkui/api/orders/{order_id:\d+}/certificate/download', [$OrderCtrl, 'downloadCertificate']],
        ['POST', '/lkui/api/csr/decode', [$OrderCtrl, 'decodeCSR']],

        // API routes - Template
        ['GET', '/lkui/api/templates', [$TemplatesCtrl, 'listTemplates']],
        //['GET', '/lkui/api/templates/{templateName}', [$TemplatesCtrl, 'getTemplate']],
        ['GET', '/lkui/api/templates/{templateId:\d+}', [$TemplatesCtrl, 'getTemplateById']],

        //EDA API routes
        ['POST', '/eda/api/ssl-order/{order_id:\d+}', [$OrderCtrl, 'submitSslOrder']],
        ['POST', '/eda/api/ssl-expiry', [$ExpiryCtrl, 'processExpiryData']],

        // Expiry API routes
        ['GET', '/lkui/api/expiry', [$ExpiryCtrl, 'listCertificates']],
        ['POST', '/lkui/api/expiry/refresh', [$ExpiryCtrl, 'refreshCertificates']],
        ['POST', '/eda/api/expiry-updates', [$ExpiryCtrl, 'logExpiryUpdate']]
        //['POST', '/lkui/api/ssl-order', [$OrderCtrl, 'forwardSslOrder']],
    ];
};
