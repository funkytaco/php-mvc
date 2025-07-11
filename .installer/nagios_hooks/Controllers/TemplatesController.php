<?php

class TemplatesController
{
    private $renderer;
    private $conn;
    private $templateModel;
    private array $data = [];

    public function __construct($renderer, $conn, $templateModel = null)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
        $this->templateModel = $templateModel;
    }

    public function get() {        
        $this->data['title'] = 'Nagios Web Hooks';
        $html = $this->renderer->render('index.html', $this->data);
        echo $html;
    }

    public function showTemplates() {
        $templates = $this->templateModel ? $this->templateModel->getAllTemplates() : $this->getTemplatesData();
        $data = [
            'appName' => 'Template',
            'title' => 'Templates',
            'templates' => $templates
        ];
        echo $this->renderer->render('templates.html', $data);
    }


    /**
     * API: List all templates
     */
    public function listTemplates($request, $response, $args)
    {
        $templates = $this->getTemplatesData();
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $templates
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * API: Get specific template by name
     */
    public function getTemplate($templateName)
    {
        $template = $this->getTemplateByName($templateName);
        
        if (!$template) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode([
                'status' => 'error',
                'message' => 'Template not found'
            ]);
            return;
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'success',
            'data' => $template
        ]);
    }

    /**
     * API: Get specific template by ID
     */
    public function getTemplateById($templateId = null)
    {
        if (!$templateId) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode([
                'status' => 'error',
                'message' => 'Template ID is required'
            ]);
            return;
        }

        try {
            $stmt = $this->conn->prepare("
                SELECT id, name, description, os_version, common_name, csr_options, 
                       cert_path, key_path, ca_path, ca_enabled, service_restart_command, 
                       created_at 
                FROM templates 
                WHERE id = ?
            ");
            $stmt->execute([$templateId]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$template) {
                header('Content-Type: application/json');
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'message' => 'Template not found'
                ]);
                return;
            }

            if ($template['csr_options']) {
                $template['csr_options'] = json_decode($template['csr_options'], true);
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'data' => $template
            ]);
        } catch (Exception $e) {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode([
                'status' => 'error',
                'message' => 'Database error: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Private helper: Get all templates from database
     */
    private function getTemplatesData()
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, name, description, os_version, common_name, csr_options, 
                       cert_path, key_path, ca_path, ca_enabled, service_restart_command, 
                       created_at 
                FROM templates 
                ORDER BY name ASC
            ");
            $stmt->execute();
            $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON csr_options for each template
            foreach ($templates as &$template) {
                if ($template['csr_options']) {
                    $template['csr_options'] = json_decode($template['csr_options'], true);
                }
            }
            
            return $templates;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Private helper: Get template by name
     */
    private function getTemplateByName($name)
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, name, description, os_version, common_name, csr_options, 
                       cert_path, key_path, ca_path, ca_enabled, service_restart_command, 
                       created_at 
                FROM templates 
                WHERE name = ?
            ");
            $stmt->execute([$name]);
            $template = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($template && $template['csr_options']) {
                $template['csr_options'] = json_decode($template['csr_options'], true);
            }
            
            return $template;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Seed default templates (RHEL6, RHEL7, RHEL8)
     * This method can be called during installation
     */
    public function seedDefaultTemplates()
    {
        $defaultTemplates = [
            [
                'name' => 'RHEL6',
                'common_name' => '*.example.com',
                'csr_options' => json_encode([
                    'key_type' => 'RSA',
                    'key_size' => 2048,
                    'digest_alg' => 'sha256',
                    'organization' => 'Example Organization',
                    'organizational_unit' => 'IT Department',
                    'locality' => 'City',
                    'state' => 'State',
                    'country' => 'US'
                ])
            ],
            [
                'name' => 'RHEL7',
                'common_name' => '*.example.com',
                'csr_options' => json_encode([
                    'key_type' => 'RSA',
                    'key_size' => 2048,
                    'digest_alg' => 'sha256',
                    'organization' => 'Example Organization',
                    'organizational_unit' => 'IT Department',
                    'locality' => 'City',
                    'state' => 'State',
                    'country' => 'US'
                ])
            ],
            [
                'name' => 'RHEL8',
                'common_name' => '*.example.com',
                'csr_options' => json_encode([
                    'key_type' => 'RSA',
                    'key_size' => 4096,
                    'digest_alg' => 'sha256',
                    'organization' => 'Example Organization',
                    'organizational_unit' => 'IT Department',
                    'locality' => 'City',
                    'state' => 'State',
                    'country' => 'US'
                ])
            ]
        ];

        try {
            foreach ($defaultTemplates as $template) {
                // Check if template already exists
                $existing = $this->getTemplateByName($template['name']);
                if (!$existing) {
                    $stmt = $this->conn->prepare("
                        INSERT INTO templates (name, common_name, csr_options, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $template['name'],
                        $template['common_name'],
                        $template['csr_options']
                    ]);
                }
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to seed templates: " . $e->getMessage());
            return false;
        }
    }
}
