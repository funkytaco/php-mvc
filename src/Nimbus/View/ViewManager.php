<?php

namespace Nimbus\View;

use Nimbus\View\TemplateEngine\EngineInterface;

/**
 * ViewManager handles view rendering with pluggable template engines
 */
class ViewManager implements ViewInterface
{
    private EngineInterface $engine;
    private array $globals = [];
    private array $config;
    
    public function __construct(EngineInterface $engine, array $config = [])
    {
        $this->engine = $engine;
        $this->config = $config;
        $this->setupDefaults();
    }
    
    /**
     * Setup default global variables
     */
    private function setupDefaults(): void
    {
        // Add default globals
        $this->globals['app_name'] = $this->config['app_name'] ?? 'Nimbus';
        $this->globals['base_url'] = $this->config['base_url'] ?? '/';
        $this->globals['year'] = date('Y');
    }
    
    /**
     * Render a template with data
     */
    public function render(string $template, array $data = []): string
    {
        // Merge global data with template-specific data
        $data = array_merge($this->globals, $data);
        
        return $this->engine->render($template, $data);
    }
    
    /**
     * Check if a template exists
     */
    public function exists(string $template): bool
    {
        return $this->engine->exists($template);
    }
    
    /**
     * Add global data available to all templates
     */
    public function addGlobal(string $key, $value): void
    {
        $this->globals[$key] = $value;
    }
    
    /**
     * Add multiple globals at once
     */
    public function addGlobals(array $globals): void
    {
        $this->globals = array_merge($this->globals, $globals);
    }
    
    /**
     * Get current template engine
     */
    public function getEngine(): EngineInterface
    {
        return $this->engine;
    }
    
    /**
     * Set a different template engine
     */
    public function setEngine(EngineInterface $engine): void
    {
        $this->engine = $engine;
    }
    
    /**
     * Get all global variables
     */
    public function getGlobals(): array
    {
        return $this->globals;
    }
}