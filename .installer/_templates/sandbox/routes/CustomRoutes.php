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
        
        // EDA webhook proxy route
        ['POST', '/api/eda/webhook', ['App\Controllers\IndexController', 'edaWebhook']],
        
        // Example protected routes (Keycloak) — one per protection level.
        // /protected needs any signed-in user; /admin also needs the 'admin'
        // realm role. Both render an explanatory page when SSO is not enabled.
        ['GET', '/protected', ['App\Controllers\ProtectedController', 'member']],
        ['GET', '/admin', ['App\Controllers\ProtectedController', 'admin']],

        // Auth routes (Keycloak)
        ['GET', '/auth/login', ['App\Controllers\AuthController', 'login']],
        ['GET', '/auth/callback', ['App\Controllers\AuthController', 'callback']],
        ['GET', '/auth/logout', ['App\Controllers\AuthController', 'logout']],
        ['GET', '/auth/configure', ['App\Controllers\AuthController', 'configure']],
        ['POST', '/auth/save-config', ['App\Controllers\AuthController', 'saveConfiguration']],
    ];
};