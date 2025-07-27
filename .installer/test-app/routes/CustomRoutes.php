<?php

return function ($injector, $renderer, $conn) {
    return [
        // Web routes
        ['GET', '/', ['App\Controllers\IndexController', 'index']],
        
        // API routes
        ['GET', '/api/items', ['App\Controllers\IndexController', 'apiList']],
        ['GET', '/api/items/{id:\d+}', ['App\Controllers\IndexController', 'apiGet']],
        ['POST', '/api/items', ['App\Controllers\IndexController', 'apiCreate']],
        ['PUT', '/api/items/{id:\d+}', ['App\Controllers\IndexController', 'apiUpdate']],
        ['DELETE', '/api/items/{id:\d+}', ['App\Controllers\IndexController', 'apiDelete']],
    ];
};