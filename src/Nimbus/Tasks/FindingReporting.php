<?php

declare(strict_types=1);

namespace Nimbus\Tasks;

/**
 * Shared read-only reporting plumbing for template checkers. Findings are
 * plain arrays; repeated messages group under one entry with accumulated
 * file:line locations.
 *
 * @phpstan-type Finding array{msg: string, locs: list<string>}
 */
trait FindingReporting
{
    protected string $templatesDir;

    /**
     * Append a finding, grouping repeat occurrences of the same message under
     * one entry so a template-wide problem prints once with its locations.
     *
     * @param list<Finding> $findings
     */
    protected static function addFinding(array &$findings, string $msg, ?string $loc = null): void
    {
        foreach ($findings as &$existing) {
            if ($existing['msg'] === $msg) {
                if ($loc !== null && !in_array($loc, $existing['locs'], true)) {
                    $existing['locs'][] = $loc;
                }
                return;
            }
        }
        unset($existing);

        $findings[] = ['msg' => $msg, 'locs' => $loc === null ? [] : [$loc]];
    }

    /**
     * @param Finding $finding
     */
    protected static function renderFinding(string $color, string $mark, array $finding): string
    {
        $line = '  ' . self::paint($color, $mark) . '  ' . $finding['msg'] . PHP_EOL;

        if ($finding['locs']) {
            $shown = array_slice($finding['locs'], 0, 3);
            $extra = count($finding['locs']) - count($shown);
            $line .= '     ' . self::paint('dark_gray', implode(', ', $shown) . ($extra > 0 ? " (+$extra more)" : '')) . PHP_EOL;
        }

        return $line;
    }

    /**
     * @param list<Finding> $errors
     * @param list<Finding> $warnings
     */
    protected static function countsLabel(array $errors, array $warnings): string
    {
        $parts = [];
        if ($errors) {
            $parts[] = count($errors) . ' error' . (count($errors) === 1 ? '' : 's');
        }
        if ($warnings) {
            $parts[] = count($warnings) . ' warning' . (count($warnings) === 1 ? '' : 's');
        }

        return implode(', ', $parts);
    }

    protected static function paint(string $color, string $str): string
    {
        return "\033[" . self::$foreground[$color] . 'm' . $str . "\033[0m";
    }

    /**
     * Terminal width for table-style output.
     */
    protected static function termWidth(): int
    {
        $width = (int) (getenv('COLUMNS') ?: exec('tput cols 2>/dev/null') ?: 80);

        return max(60, min(120, $width));
    }

    /**
     * A ─ horizontal rule spanning the terminal, optionally with a label.
     */
    protected static function hr(?string $label = null): string
    {
        $width = self::termWidth();

        if ($label === null || $label === '') {
            return self::paint('dark_gray', str_repeat('─', $width)) . PHP_EOL;
        }

        $label = ' ' . $label . ' ';
        $left = max(2, intdiv($width - mb_strlen($label), 2));
        $right = max(2, $width - mb_strlen($label) - $left);

        return self::paint('dark_gray', str_repeat('─', $left))
            . self::paint('white', $label)
            . self::paint('dark_gray', str_repeat('─', $right)) . PHP_EOL;
    }

    /**
     * @return string[] every file in the template, as template-relative paths
     */
    protected function templateFiles(string $templatePath): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templatePath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($templatePath) + 1);
            if (str_starts_with($rel, '.git/') || str_contains($rel, '/node_modules/')) {
                continue;
            }
            $files[] = $rel;
        }

        sort($files);

        return $files;
    }

    /**
     * @return string[]
     */
    protected function getAvailableTemplates(): array
    {
        if (!is_dir($this->templatesDir)) {
            return [];
        }

        $templates = [];
        foreach (scandir($this->templatesDir) ?: [] as $dir) {
            if ($dir !== '.' && $dir !== '..' && is_dir($this->templatesDir . '/' . $dir)) {
                $templates[] = $dir;
            }
        }

        return $templates;
    }
}
