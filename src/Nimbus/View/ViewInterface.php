<?php

namespace Nimbus\View;

/**
 * ViewInterface defines the contract for view rendering
 */
interface ViewInterface
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
     * Add global data available to all templates
     * 
     * @param string $key
     * @param mixed $value
     */
    public function addGlobal(string $key, $value): void;
}