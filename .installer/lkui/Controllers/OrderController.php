<?php

namespace App\Controllers;

class OrderController
{
    public function createOrder($hostId)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }

    public function updateOrder($hostId, $certContent)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_COMPLETED',
            'cert_content' => $certContent,
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }

    public function getOrder($hostId)
    {
        return [
            'id' => 1,
            'host_id' => $hostId,
            'status' => 'ORDER_PENDING',
            'issued_at' => date('Y-m-d H:i:s')
        ];
    }
}
