<?php

class TemplatesController
{
    private $renderer;
    private $conn;

    public function __construct($renderer, $conn)
    {
        $this->renderer = $renderer;
        $this->conn = $conn;
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
    public function getTemplate($request, $response, $args)
    {
        $templateName = $args['name'];
        $template = $this->getTemplateByName($templateName);
        
        if (!$template) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Template not found'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'success',
            'data' => $template
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    /**
     * Private helper: Get all templates from database
     */
    private function getTemplatesData()
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, name, common_name, csr_options, created_at 
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
                SELECT id, name, common_name, csr_options, created_at 
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
