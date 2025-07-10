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
            SELECT id, name, description, os_version, common_name, csr_options, 
                   cert_path, key_path, ca_path, ca_enabled, service_restart_command, 
                   created_at 
            FROM templates 
            ORDER BY name ASC
        ");
        $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON csr_options for each template
        foreach ($templates as &$template) {
            if ($template['csr_options']) {
                $template['csr_options'] = json_decode($template['csr_options'], true);
            }
        }
        
        return $templates;
    }

    public function getTemplateById($id) {
        $stmt = $this->db->prepare("
            SELECT id, name, description, os_version, common_name, csr_options, 
                   cert_path, key_path, ca_path, ca_enabled, service_restart_command, 
                   created_at 
            FROM templates 
            WHERE id = :id
        ");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($template && $template['csr_options']) {
            $template['csr_options'] = json_decode($template['csr_options'], true);
        }
        
        return $template;
    }

    public function getTemplateByName($name) {
        $stmt = $this->db->prepare("
            SELECT id, name, description, os_version, common_name, csr_options, 
                   cert_path, key_path, ca_path, ca_enabled, service_restart_command, 
                   created_at 
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
            INSERT INTO templates (name, description, os_version, common_name, csr_options, 
                                   cert_path, key_path, ca_path, ca_enabled, service_restart_command) 
            VALUES (:name, :description, :os_version, :common_name, :csr_options, 
                    :cert_path, :key_path, :ca_path, :ca_enabled, :service_restart_command)
        ");
        
        $stmt->bindParam(':name', $data['name'], PDO::PARAM_STR);
        $stmt->bindParam(':description', $data['description'], PDO::PARAM_STR);
        $stmt->bindParam(':os_version', $data['os_version'], PDO::PARAM_STR);
        $stmt->bindParam(':common_name', $data['common_name'], PDO::PARAM_STR);
        $stmt->bindParam(':csr_options', $data['csr_options'], PDO::PARAM_STR);
        $stmt->bindParam(':cert_path', $data['cert_path'], PDO::PARAM_STR);
        $stmt->bindParam(':key_path', $data['key_path'], PDO::PARAM_STR);
        $stmt->bindParam(':ca_path', $data['ca_path'], PDO::PARAM_STR);
        $stmt->bindParam(':ca_enabled', $data['ca_enabled'], PDO::PARAM_BOOL);
        $stmt->bindParam(':service_restart_command', $data['service_restart_command'], PDO::PARAM_STR);
        
        return $stmt->execute();
    }

    /**
     * Get templates formatted for dropdown forms
     */
    public function getTemplatesForForm() {
        try {
            $stmt = $this->db->query("
                SELECT id, name, description, os_version, csr_options
                FROM templates 
                ORDER BY name ASC
            ");
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse csr_options to get key size for display
            foreach ($templates as &$template) {
                if ($template['csr_options']) {
                    $csrOptions = json_decode($template['csr_options'], true);
                    $template['key_type'] = $csrOptions['key_type'] ?? 'RSA';
                    $template['key_size'] = $csrOptions['key_size'] ?? 2048;
                    $template['display_name'] = $template['name'] . ' (' . $template['key_type'] . ' ' . $template['key_size'] . ')';
                    if ($template['os_version']) {
                        $template['display_name'] .= ' - ' . $template['os_version'];
                    }
                    // Keep csr_options as JSON string for JavaScript consumption
                    $template['csr_options'] = $template['csr_options'];
                } else {
                    $template['display_name'] = $template['name'];
                    $template['csr_options'] = '{}';
                }
            }
            
            return $templates;
        } catch (Exception $e) {
            return [];
        }
    }
}
