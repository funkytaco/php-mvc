<?php

declare(strict_types=1);

namespace Main\Renderer;

use Mustache_Engine;

/**
 * Mustache template renderer implementation
 * 
 * @package Main\Renderer
 * @author Nimbus Framework
 * @license Apache-2.0
 * @copyright 2025 SmallCloud, LLC
 */
class MustacheRenderer implements Renderer
{
    /** @var Mustache_Engine The Mustache template engine */
    private Mustache_Engine $engine;

    /**
     * Constructor
     * 
     * @param Mustache_Engine $engine The Mustache engine instance
     */
    public function __construct(Mustache_Engine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Render a template with the given data
     * 
     * @param string $template The template name or path
     * @param array<string, mixed> $data Data to pass to the template
     * @return string The rendered output
     */
    public function render(string $template, array $data = []): string
    {
        return $this->engine->render($template, $data);
    }
}