<?php

return function ($injector, $renderer, $conn) {
    return [
        // Web routes
        ['GET', '/', ['App\Controllers\DemoController', 'index']],
        
        // API routes
        ['GET', '/api/items', ['App\Controllers\DemoController', 'apiList']],
        ['GET', '/api/items/{id:\d+}', ['App\Controllers\DemoController', 'apiGet']],
        ['POST', '/api/items', ['App\Controllers\DemoController', 'apiCreate']],
        ['PUT', '/api/items/{id:\d+}', ['App\Controllers\DemoController', 'apiUpdate']],
        ['DELETE', '/api/items/{id:\d+}', ['App\Controllers\DemoController', 'apiDelete']],
        
        // EDA webhook proxy route
        ['POST', '/api/eda/webhook', ['App\Controllers\DemoController', 'edaWebhook']],
    ];
};