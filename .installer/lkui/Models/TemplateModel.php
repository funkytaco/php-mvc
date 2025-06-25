<?php

namespace Models;

use PDO;

class TemplateModel {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getAllTemplates() {
        $stmt = $this->db->query("SELECT * FROM templates");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTemplateById($id) {
        $stmt = $this->db->prepare("SELECT * FROM templates WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getTemplateByName($name) {
        $stmt = $this->db->prepare("SELECT * FROM templates WHERE name = :name");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createTemplate($name, $commonName, $csrOptions) {
        $stmt = $this->db->prepare("
            INSERT INTO templates (name, common_name, csr_options) 
            VALUES (:name, :common_name, :csr_options)
        ");
        $stmt->bindParam(':name', $name, PDO::FETCH_ASSOC);
        $stmt->bindParam(':common_name', $commonName, PDO::FETCH_ASSOC);
        $stmt->bindParam(':csr_options', $csrOptions, PDO::FETCH_ASSOC);
        return $stmt->execute();
    }
}
