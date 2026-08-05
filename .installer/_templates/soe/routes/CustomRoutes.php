<?php

declare(strict_types=1);

/**
 * SOE routes: Order Gateway (intake) and Order Tracker (read-only).
 * Route tuples: [METHOD, path, [Controller, method]]
 */
return function ($injector, $renderer, $conn) {
    return [
        // Home — redirect to the gateway
        ['GET', '/', ['App\Controllers\OrderController', 'gateway']],

        // Order Gateway (intake form + submission)
        ['GET', '/order', ['App\Controllers\OrderController', 'gateway']],
        ['POST', '/order', ['App\Controllers\OrderController', 'submit']],

        // Order Tracker (list + detail views)
        ['GET', '/tracker', ['App\Controllers\OrderController', 'trackerList']],
        ['GET', '/tracker/{ref}', ['App\Controllers\OrderController', 'trackerDetail']],

        // JSON API for tracker polling (and dev testing)
        ['GET', '/api/orders/{ref}', ['App\Controllers\OrderController', 'apiTrackerDetail']],

        // Dev-only: simulate Helix progression (disabled in production)
        ['POST', '/order/{ref}/advance', ['App\Controllers\OrderController', 'advanceTicket']],
    ];
};