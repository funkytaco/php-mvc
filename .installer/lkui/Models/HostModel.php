<?php

namespace App\Models;

class HostModel
{
    protected $table = 'hosts';

    public function getSchema()
    {
        return [
            'id' => 'integer',
            'template_id' => 'integer',
            'csr_content' => 'text',
            'common_name' => 'string',
            'status' => 'string'
        ];
    }

    public function create($templateId, $csrContent, $commonName)
    {
        // TODO: Implement host creation
        return [
            'id' => 1,
            'template_id' => $templateId,
            'csr_content' => $csrContent,
            'common_name' => $commonName,
            'status' => 'CSR_GENERATED'
        ];
    }
}
