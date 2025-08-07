<?php

declare(strict_types=1);

namespace Main\Renderer;

/**
 * Interface for template rendering engines
 * 
 * @package Main\Renderer
 * @author Nimbus Framework
 * @license Apache-2.0
 * @copyright 2025 SmallCloud, LLC
 */
interface Renderer
{
    /**
     * Render a template with the given data
     * 
     * @param string $template The template name or path
     * @param array<string, mixed> $data Data to pass to the template
     * @return string The rendered output
     */
    public function render(string $template, array $data = []): string;
}