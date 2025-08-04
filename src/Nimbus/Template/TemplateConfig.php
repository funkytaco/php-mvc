<?php

namespace Nimbus\Template;

class TemplateConfig
{
    private string $configPath;
    private string $templatesPath;
    private array $config;
    private static ?TemplateConfig $instance = null;
    
    private function __construct()
    {
        $this->configPath = getcwd() . '/nimbus-config.json';
        $this->templatesPath = getcwd() . '/.installer/_templates';
        $this->loadConfig();
    }
    
    public static function getInstance(): TemplateConfig
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getDefaultTemplate(): string
    {
        $this->refreshAvailableTemplates();
        return $this->config['default_template'] ?? $this->getFirstAvailableTemplate();
    }
    
    public function setDefaultTemplate(string $templateName): void
    {
        if (!$this->isValidTemplate($templateName)) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }
        
        $this->config['default_template'] = $templateName;
        $this->saveConfig();
    }
    
    public function getAvailableTemplates(): array
    {
        $this->refreshAvailableTemplates();
        return $this->config['available_templates'] ?? [];
    }
    
    public function isValidTemplate(string $templateName): bool
    {
        $this->refreshAvailableTemplates();
        return in_array($templateName, $this->config['available_templates'] ?? []);
    }
    
    public function refreshAvailableTemplates(): void
    {
        if (!is_dir($this->templatesPath)) {
            $this->config['available_templates'] = [];
            return;
        }
        
        $directories = array_diff(scandir($this->templatesPath), ['.', '..']);
        $templates = [];
        
        foreach ($directories as $dir) {
            $templatePath = $this->templatesPath . '/' . $dir;
            if (is_dir($templatePath)) {
                $templates[] = $dir;
            }
        }
        
        $this->config['available_templates'] = $templates;
        $this->config['last_scanned'] = date('c');
        $this->saveConfig();
    }
    
    public function getTemplateAliases(): array
    {
        return $this->config['template_aliases'] ?? [];
    }
    
    public function addTemplateAlias(string $alias, string $templateName): void
    {
        if (!$this->isValidTemplate($templateName)) {
            throw new \InvalidArgumentException("Template '{$templateName}' not found");
        }
        
        if (!isset($this->config['template_aliases'])) {
            $this->config['template_aliases'] = [];
        }
        
        $this->config['template_aliases'][$alias] = $templateName;
        $this->saveConfig();
    }
    
    public function removeTemplateAlias(string $alias): void
    {
        if (isset($this->config['template_aliases'][$alias])) {
            unset($this->config['template_aliases'][$alias]);
            $this->saveConfig();
        }
    }
    
    public function resolveTemplate(string $nameOrAlias): string
    {
        $aliases = $this->getTemplateAliases();
        return $aliases[$nameOrAlias] ?? $nameOrAlias;
    }
    
    private function getFirstAvailableTemplate(): string
    {
        $templates = $this->getAvailableTemplates();
        if (empty($templates)) {
            throw new \RuntimeException('No templates found in ' . $this->templatesPath);
        }
        return $templates[0];
    }
    
    private function loadConfig(): void
    {
        if (!file_exists($this->configPath)) {
            $this->config = $this->getDefaultConfig();
            $this->saveConfig();
            return;
        }
        
        try {
            $content = file_get_contents($this->configPath);
            $this->config = json_decode($content, true) ?? $this->getDefaultConfig();
        } catch (\Exception $e) {
            $this->config = $this->getDefaultConfig();
        }
    }
    
    private function saveConfig(): void
    {
        $json = json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($this->configPath, $json);
    }
    
    private function getDefaultConfig(): array
    {
        return [
            'default_template' => 'nimbus-app-php',
            'template_aliases' => [],
            'available_templates' => [],
            'last_scanned' => null
        ];
    }
}