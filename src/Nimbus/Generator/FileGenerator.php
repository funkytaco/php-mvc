<?php

namespace Nimbus\Generator;

use Nimbus\View\TemplateEngine\MustacheEngine;

/**
 * FileGenerator uses MustacheEngine to generate configuration files from templates
 */
class FileGenerator
{
    private MustacheEngine $engine;
    private string $baseDir;

    public function __construct(string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? getcwd();
        $this->engine = new MustacheEngine();
    }

    /**
     * Generate a configuration file from a template
     */
    public function generateFile(string $templatePath, string $outputPath, array $data): void
    {
        if (!file_exists($templatePath)) {
            throw new \InvalidArgumentException("Template file not found: {$templatePath}");
        }

        // Read template content directly (not using Mustache loader)
        $templateContent = file_get_contents($templatePath);
        
        // Create temporary Mustache engine for string templates
        $mustache = new \Mustache_Engine([
            'cache' => sys_get_temp_dir() . '/file_generator_cache',
            'cache_file_mode' => 0666,
            'charset' => 'UTF-8',
            'strict_callables' => true,
        ]);

        // Render the template with data
        $renderedContent = $mustache->render($templateContent, $data);

        // Ensure output directory exists
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Write the generated file
        file_put_contents($outputPath, $renderedContent);

        // Make executable if it's a shell script
        if (pathinfo($outputPath, PATHINFO_EXTENSION) === 'sh' || 
            strpos($renderedContent, '#!/') === 0) {
            chmod($outputPath, 0755);
        }
    }

    /**
     * Generate EDA playbook from template
     */
    public function generatePlaybook(string $templatePath, string $outputPath, array $data): void
    {
        $this->generateFile($templatePath, $outputPath, $data);
    }

    /**
     * Generate EDA rulebook from template  
     */
    public function generateRulebook(string $templatePath, string $outputPath, array $data): void
    {
        $this->generateFile($templatePath, $outputPath, $data);
    }

    /**
     * Load template variables from app template's app.config.php
     */
    public function loadTemplateVariables(string $templateName, string $appName): array
    {
        $configPath = $this->baseDir . "/.installer/_templates/{$templateName}/app.config.php";
        
        if (!file_exists($configPath)) {
            return [];
        }

        // Load the config file
        $config = include $configPath;
        
        if (!is_array($config)) {
            return [];
        }

        // Extract template variables if they exist
        $templateVars = $config['template_vars'] ?? [];
        
        // Add standard app variables
        $standardVars = [
            'APP_NAME' => $appName,
            'app_name' => $appName,
            'APP_NAME_LOWER' => strtolower($appName),
            'APP_NAME_UPPER' => strtoupper($appName),
        ];

        return array_merge($standardVars, $templateVars);
    }

    /**
     * Generate multiple files from a template directory
     * Templates ending in .mustache will have the extension stripped in output
     */
    public function generateFromDirectory(string $templateDir, string $outputDir, array $data): void
    {
        if (!is_dir($templateDir)) {
            throw new \InvalidArgumentException("Template directory not found: {$templateDir}");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = substr($file->getPathname(), strlen($templateDir) + 1);
                
                // Strip .mustache extension from output path if present
                if (pathinfo($relativePath, PATHINFO_EXTENSION) === 'mustache') {
                    $relativePath = substr($relativePath, 0, -9); // Remove .mustache
                }
                
                $outputPath = $outputDir . '/' . $relativePath;
                
                $this->generateFile($file->getPathname(), $outputPath, $data);
            }
        }
    }
}