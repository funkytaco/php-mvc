<?php

namespace App\Models;

class OrderModel
{
    protected $table = 'orders';

    public function getSchema()
    {
        return [
            'id' => 'integer',
            'host_id' => 'integer',
            'status' => 'string',
            'cert_content' => 'text',
            'issued_at' => 'datetime'
        ];
    }

    public function create($hostId)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }

    public function updateCertificate($hostId, $certContent)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_COMPLETED',
            'cert_content' => $certContent,
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }
}
