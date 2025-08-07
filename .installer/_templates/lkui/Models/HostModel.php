<?php

namespace Models;

use PDO;

class HostModel {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function createHost($templateId, $commonName, $csrContent) {
        $stmt = $this->db->prepare("
            INSERT INTO hosts (template_id, common_name, csr_content, status)
            VALUES (:template_id, :common_name, :csr_content, 'pending')
        ");
        $stmt->bindParam(':template_id', $templateId, PDO::PARAM_INT);
        $stmt->bindParam(':common_name', $commonName, PDO::PARAM_STR);
        $stmt->bindParam(':csr_content', $csrContent, PDO::PARAM_STR);
        return $stmt->execute() ? $this->db->lastInsertId() : false;
    }

    public function getHostById($id) {
        $stmt = $this->db->prepare("
            SELECT h.*, t.name as template_name 
            FROM hosts h
            JOIN templates t ON h.template_id = t.id
            WHERE h.id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAllHosts() {
        $stmt = $this->db->query("
            SELECT h.*, t.name as template_name 
            FROM hosts h
            JOIN templates t ON h.template_id = t.id
            ORDER BY h.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateHostStatus($id, $status) {
        $stmt = $this->db->prepare("
            UPDATE hosts SET status = :status WHERE id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':status', $status, PDO::PARAM_STR);
        return $stmt->execute();
    }
}
