<?php

namespace Models;

use PDO;

class TemplateModel {
    private $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getAllTemplates() {
        $stmt = $this->db->query("
            SELECT id, name, description
            FROM templates 
            ORDER BY name ASC
        ");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        
        return $templates;
    }

    public function getTemplateById($id) {
        $stmt = $this->db->prepare("
            SELECT id, name, description
            FROM templates 
            WHERE id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
                
        return $template;
    }

    public function getTemplateByName($name) {
        $stmt = $this->db->prepare("
            SELECT id, name, description
            FROM templates 
            WHERE name = :name
        ");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->execute();
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template && $template['csr_options']) {
            $template['csr_options'] = json_decode($template['csr_options'], true);
        }
        
        return $template;
    }

    public function createTemplate($data) {
        $stmt = $this->db->prepare("
            INSERT INTO templates (name, description) 
            VALUES (:name, :description)
        ");
        
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':description', $data['description'], PDO::PARAM_STR);
        
        return $stmt->execute();
    }

    /**
     * Get templates formatted for dropdown forms
     */
    public function getTemplatesForForm() {
        try {
            $stmt = $this->db->query("
                SELECT id, name, description
                FROM templates 
                ORDER BY name ASC
            ");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            
            return $templates;
        } catch (Exception $e) {
            return [];
        }
    }
}
