<?php

require_once('Controllers/TemplatesController.php');
require_once('Models/TemplateModel.php');

return function ($injector, $renderer, $conn) {
    $mod_date = $injector->make('Main\Modules\Date_Module');

    // Create model instances
    $templateModel = new \Models\TemplateModel($conn);
    // Create controller instances with model dependencies
    $TemplatesCtrl = new TemplatesController($renderer, $conn, $templateModel);

    return [
        //Homepage route
        ['GET', '/', [$TemplatesCtrl, 'get']],
        //Templates routes
        ['GET', '/templates', [$TemplatesCtrl, 'showTemplates']]
    ];
};