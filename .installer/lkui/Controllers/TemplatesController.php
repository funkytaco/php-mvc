<?php

namespace App\Controllers;

class TemplatesController
{
    public function listTemplates()
    {
        // TODO: Implement template listing
        return [];
    }

    public function getTemplate($templateName)
    {
        // TODO: Implement template retrieval
        return [
            'common_name' => '*.example.com',
            'csr_options' => []
        ];
    }
}
