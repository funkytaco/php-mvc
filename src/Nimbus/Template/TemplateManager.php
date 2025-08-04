<?php

namespace Nimbus\Template;

class TemplateManager
{
    private string $templatesPath;
    private array $availableTemplates = [];
    private TemplateConfig $templateConfig;
    
    public function __construct()
    {
        $this->templatesPath = getcwd() . '/.installer/_templates';
        $this->templateConfig = TemplateConfig::getInstance();
        $this->scanTemplates();
    }
    
    
    /**
     * Scan the templates directory to find available templates
     */
    private function scanTemplates(): void
    {
        if (!is_dir($this->templatesPath)) {
            throw new \RuntimeException("Templates directory not found: {$this->templatesPath}");
        }
        
        $directories = array_diff(scandir($this->templatesPath), ['.', '..']);
        
        foreach ($directories as $dir) {
            $templatePath = $this->templatesPath . '/' . $dir;
            if (is_dir($templatePath)) {
                $this->availableTemplates[$dir] = [
                    'name' => $dir,
                    'path' => $templatePath,
                    'has_controllers' => is_dir($templatePath . '/Controllers'),
                    'has_views' => is_dir($templatePath . '/Views'),
                    'has_models' => is_dir($templatePath . '/Models'),
                    'has_routes' => file_exists($templatePath . '/routes/CustomRoutes.php'),
                    'has_config' => file_exists($templatePath . '/app.nimbus.json'),
                    'has_database' => is_dir($templatePath . '/database'),
                ];
            }
        }
    }
    
    /**
     * Get list of available templates
     */
    public function getAvailableTemplates(): array
    {
        return $this->availableTemplates;
    }
    
    /**
     * Resolve template name from alias
     */
    public function resolveTemplate(string $nameOrAlias): string
    {
        return $this->templateConfig->resolveTemplate($nameOrAlias);
    }
    
    /**
     * Check if a template exists (supports aliases)
     */
    public function templateExists(string $templateName): bool
    {
        $resolvedName = $this->resolveTemplate($templateName);
        return isset($this->availableTemplates[$resolvedName]);
    }
    
    /**
     * Get template info (supports aliases)
     */
    public function getTemplateInfo(string $templateName): ?array
    {
        $resolvedName = $this->resolveTemplate($templateName);
        return $this->availableTemplates[$resolvedName] ?? null;
    }
    
    /**
     * Get the path to a specific template (supports aliases)
     */
    public function getTemplatePath(string $templateName): string
    {
        $resolvedName = $this->resolveTemplate($templateName);
        
        if (!isset($this->availableTemplates[$resolvedName])) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }
        
        return $this->availableTemplates[$resolvedName]['path'];
    }
    
    /**
     * Add a template alias
     */
    public function addAlias(string $alias, string $templateName): void
    {
        $this->templateConfig->addTemplateAlias($alias, $templateName);
    }
    
    /**
     * Remove a template alias
     */
    public function removeAlias(string $alias): void
    {
        $this->templateConfig->removeTemplateAlias($alias);
    }
    
    /**
     * Get all aliases
     */
    public function getAliases(): array
    {
        return $this->templateConfig->getTemplateAliases();
    }
    
    /**
     * Copy a template to create a new app
     */
    public function copyTemplate(string $templateName, string $targetPath): void
    {
        if (!$this->templateExists($templateName)) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }
        
        $sourcePath = $this->getTemplatePath($templateName);
        
        if (file_exists($targetPath)) {
            throw new \RuntimeException("Target path already exists: {$targetPath}");
        }
        
        $this->copyDirectory($sourcePath, $targetPath);
    }
    
    /**
     * Recursively copy a directory
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($source)) {
            throw new \RuntimeException("Source directory not found: {$source}");
        }
        
        mkdir($destination, 0755, true);
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            $targetPath = $destination . '/' . $iterator->getSubPathName();
            
            if ($file->isDir()) {
                mkdir($targetPath, 0755);
            } else {
                copy($file->getPathname(), $targetPath);
            }
        }
    }
    
    /**
     * Validate template structure
     */
    public function validateTemplate(string $templateName): array
    {
        if (!$this->templateExists($templateName)) {
            return ['valid' => false, 'errors' => ["Template '{$templateName}' not found"]];
        }
        
        $errors = [];
        $templateInfo = $this->getTemplateInfo($templateName);
        $templatePath = $templateInfo['path'];
        
        // Check for required directories/files
        $requiredStructure = [
            'Controllers' => 'directory',
            'Views' => 'directory',
            'Models' => 'directory',
            'routes/CustomRoutes.php' => 'file',
            'app.nimbus.json' => 'file',
        ];
        
        foreach ($requiredStructure as $path => $type) {
            $fullPath = $templatePath . '/' . $path;
            
            if ($type === 'directory' && !is_dir($fullPath)) {
                $errors[] = "Missing required directory: {$path}";
            } elseif ($type === 'file' && !file_exists($fullPath)) {
                $errors[] = "Missing required file: {$path}";
            }
        }
        
        // Validate app.nimbus.json if it exists
        $configPath = $templatePath . '/app.nimbus.json';
        if (file_exists($configPath)) {
            $configContent = file_get_contents($configPath);
            $config = json_decode($configContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "Invalid JSON in app.nimbus.json: " . json_last_error_msg();
            } elseif (!isset($config['type'])) {
                $errors[] = "app.nimbus.json missing required 'type' field";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * List all templates with their validation status
     */
    public function listTemplatesWithStatus(): array
    {
        $templates = [];
        
        foreach ($this->availableTemplates as $name => $info) {
            $validation = $this->validateTemplate($name);
            $templates[$name] = array_merge($info, [
                'valid' => $validation['valid'],
                'errors' => $validation['errors']
            ]);
        }
        
        return $templates;
    }
}