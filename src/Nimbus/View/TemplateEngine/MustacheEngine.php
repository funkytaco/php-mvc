<?php

namespace Nimbus\View\TemplateEngine;

use Mustache_Engine;
use Mustache_Loader_FilesystemLoader;

/**
 * MustacheEngine implements Mustache template rendering
 */
class MustacheEngine implements EngineInterface
{
    private Mustache_Engine $mustache;
    private string $templateDirectory;
    
    public function __construct(string $templateDirectory = null)
    {
        $this->templateDirectory = $templateDirectory ?? VIEWS_DIR;
        $this->initializeMustache();
    }
    
    /**
     * Initialize Mustache engine
     */
    private function initializeMustache(): void
    {
        $this->mustache = new Mustache_Engine([
            'loader' => new Mustache_Loader_FilesystemLoader($this->templateDirectory),
            'partials_loader' => new Mustache_Loader_FilesystemLoader($this->templateDirectory . '/partials'),
            'cache' => sys_get_temp_dir() . '/mustache_cache',
            'cache_file_mode' => 0666,
            'charset' => 'UTF-8',
            'strict_callables' => true,
        ]);
    }
    
    /**
     * Render a template with data
     */
    public function render(string $template, array $data = []): string
    {
        // Remove .mustache extension if provided
        $template = preg_replace('/\.mustache$/', '', $template);
        
        return $this->mustache->render($template, $data);
    }
    
    /**
     * Check if a template exists
     */
    public function exists(string $template): bool
    {
        // Remove .mustache extension if provided
        $template = preg_replace('/\.mustache$/', '', $template);
        
        $templatePath = $this->templateDirectory . '/' . $template . '.mustache';
        return file_exists($templatePath);
    }
    
    /**
     * Set the template directory
     */
    public function setTemplateDirectory(string $directory): void
    {
        $this->templateDirectory = $directory;
        $this->initializeMustache();
    }
    
    /**
     * Get the template directory
     */
    public function getTemplateDirectory(): string
    {
        return $this->templateDirectory;
    }
    
    /**
     * Get the underlying Mustache engine
     */
    public function getMustacheEngine(): Mustache_Engine
    {
        return $this->mustache;
    }
}