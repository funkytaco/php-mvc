<?php

namespace Nimbus\View\TemplateEngine;

/**
 * EngineInterface defines the contract for template engines
 */
interface EngineInterface
{
    /**
     * Render a template with data
     * 
     * @param string $template Template name or path
     * @param array $data Data to pass to the template
     * @return string Rendered output
     */
    public function render(string $template, array $data = []): string;
    
    /**
     * Check if a template exists
     * 
     * @param string $template Template name or path
     * @return bool
     */
    public function exists(string $template): bool;
    
    /**
     * Set the template directory
     * 
     * @param string $directory
     */
    public function setTemplateDirectory(string $directory): void;
    
    /**
     * Get the template directory
     * 
     * @return string
     */
    public function getTemplateDirectory(): string;
}