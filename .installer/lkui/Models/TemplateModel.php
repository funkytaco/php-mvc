<?php

namespace App\Models;

class TemplateModel
{
    protected $table = 'templates';

    public function getSchema()
    {
        return [
            'id' => 'integer',
            'name' => 'string',
            'common_name' => 'string',
            'csr_options' => 'json'
        ];
    }

    public function seedDefaults()
    {
        return [
            [
                'name' => 'RHEL6',
                'common_name' => '*.example.com',
                'csr_options' => []
            ],
            [
                'name' => 'RHEL7',
                'common_name' => '*.example.com',
                'csr_options' => []
            ],
            [
                'name' => 'RHEL8',
                'common_name' => '*.example.com',
                'csr_options' => []
            ]
        ];
    }
}
