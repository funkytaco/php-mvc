<?php
    return [
        
        ['GET', '/templates', function($request, $response) use ($container) {
            return $container->get('Icarus\Controllers\TemplateManagerController')->get($request, $response);
        }],
        ['GET', '/templates/[i:id]', function($request, $response) use ($container) {
            return $container->get('Icarus\Controllers\TemplateManagerController')->getTemplate($request, $response);
        }],
        ['POST', '/templates/[i:id]', function($request, $response) use ($container) {
            return $container->get('Icarus\Controllers\TemplateManagerController')->saveTemplate($request, $response);
        }],
        ['GET', '/templates/new', function($request, $response) use ($container) {
            return $container->get('Icarus\Controllers\TemplateManagerController')->newTemplate($request, $response);
        }],
        ['POST', '/templates/new', function($request, $response) use ($container) {
            return $container->get('Icarus\Controllers\TemplateManagerController')->createTemplate($request, $response);
        }]
    ];