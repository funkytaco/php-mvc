<?php

namespace Models;

use PDO;

class OrderModel {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function createOrder($hostId) {
        $stmt = $this->db->prepare("
            INSERT INTO orders (host_id, status)
            VALUES (:host_id, 'pending')
        ");
        $stmt->bindParam(':host_id', $hostId, PDO::PARAM_INT);
        return $stmt->execute() ? $this->db->lastInsertId() : false;
    }

    public function getOrderById($id) {
        $stmt = $this->db->prepare("
            SELECT o.*, h.common_name, h.csr_content
            FROM orders o
            JOIN hosts h ON o.host_id = h.id
            WHERE o.id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllOrders() {
        $stmt = $this->db->query("
            SELECT o.*, h.common_name
            FROM orders o
            JOIN hosts h ON o.host_id = h.id
            ORDER BY o.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateOrderCertificate($id, $certContent) {
        $stmt = $this->db->prepare("
            UPDATE orders 
            SET cert_content = :cert_content, 
                status = 'completed',
                issued_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':cert_content', $certContent, PDO::PARAM_STR);
        return $stmt->execute();
    }

    public function updateOrderStatus($id, $status) {
        $stmt = $this->db->prepare("
            UPDATE orders SET status = :status WHERE id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);
        return $stmt->execute();
    }
}
