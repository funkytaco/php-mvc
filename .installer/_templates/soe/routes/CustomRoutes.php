<?php

/**
 * Host Build — Order & Tracking Gateway routes.
 *
 * Four surfaces (spec §5) plus the JSON API of DESIGN-DD §8, which is served
 * by App\Controllers\ApiController rather than a separate micro-framework.
 *
 * Every surface and every API endpoint is role-scoped server-side inside the
 * controller (Golden Rule 7) — the route table itself grants nothing.
 */

return function ($injector, $renderer, $conn) {
    return [
        // Landing — redirects a signed-in user to their persona's surface.
        ['GET', '/', ['App\Controllers\IndexController', 'index']],

        // ---- Catalog Builder — App Owner (FR-CAT-*) ----------------------
        ['GET',  '/catalog', ['App\Controllers\CatalogController', 'index']],
        ['POST', '/catalog', ['App\Controllers\CatalogController', 'publish']],

        // ---- Order Gateway — Requester (FR-ORD-*) ------------------------
        ['GET',  '/order', ['App\Controllers\OrderController', 'index']],
        ['POST', '/order', ['App\Controllers\OrderController', 'submit']],

        // ---- Order Tracker — Customer, read-only (FR-TRK-*) --------------
        ['GET', '/tracker',       ['App\Controllers\TrackerController', 'index']],
        ['GET', '/tracker/{ref}', ['App\Controllers\TrackerController', 'detail']],

        // ---- Team SOPs & Task View — Team Member (FR-TASK-*) -------------
        ['GET',  '/sops',                ['App\Controllers\TaskController', 'index']],
        ['GET',  '/sops/{team}',         ['App\Controllers\TaskController', 'team']],
        ['POST', '/sops/{team}/notes',   ['App\Controllers\TaskController', 'addNote']],

        // ---- JSON API (DESIGN-DD §8) -------------------------------------
        ['GET',  '/api/catalog',            ['App\Controllers\ApiController', 'catalog']],
        ['POST', '/api/orders/resolve',     ['App\Controllers\ApiController', 'resolve']],
        ['GET',  '/api/orders',             ['App\Controllers\ApiController', 'orders']],
        ['GET',  '/api/orders/{ref}',       ['App\Controllers\ApiController', 'order']],
        ['GET',  '/api/sops/{team}',        ['App\Controllers\ApiController', 'sop']],
        ['POST', '/api/sops/{team}/notes',  ['App\Controllers\ApiController', 'addNote']],

        // Dev/demo only — gated on app_env inside the controller (DESIGN-DD §4).
        ['POST', '/api/dev/helix/advance',  ['App\Controllers\ApiController', 'advance']],

        // ---- Auth (Keycloak) ---------------------------------------------
        ['GET',  '/auth/login',     ['App\Controllers\AuthController', 'login']],
        ['GET',  '/auth/callback',  ['App\Controllers\AuthController', 'callback']],
        ['GET',  '/auth/logout',    ['App\Controllers\AuthController', 'logout']],
        ['GET',  '/auth/configure', ['App\Controllers\AuthController', 'configure']],
        ['POST', '/auth/save-config', ['App\Controllers\AuthController', 'saveConfiguration']],
        // Callback target for EDA's configure-keycloak playbook.
        ['POST', '/api/keycloak/configured', ['App\Controllers\AuthController', 'keycloakConfigured']],
    ];
};
