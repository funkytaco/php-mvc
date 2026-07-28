<?php

declare(strict_types=1);

namespace Nimbus\Template;

/**
 * Static analysis of one mustache view source, using the real Mustache
 * tokenizer so delimiters, comments and sections are handled exactly like
 * at request time. Read-only — never renders to output.
 */
final class MustacheAnalyzer
{
    /**
     * @return array{
     *   vars: array<string, list<int>>,
     *   partials: array<string, list<int>>,
     *   syntaxError: string|null
     * } vars are root-level variable/section names => lines; partials are
     *   partial names => lines (any depth); syntaxError set when the view
     *   would throw at request time.
     */
    public static function analyze(string $source): array
    {
        $vars = [];
        $partials = [];

        try {
            $tokenizer = new \Mustache_Tokenizer();
            $tokens = $tokenizer->scan($source);
            // Parser catches structural errors (unclosed / misnested sections)
            // that the tokenizer alone does not.
            (new \Mustache_Parser())->parse($tokens);
        } catch (\Mustache_Exception_SyntaxException $e) {
            return ['vars' => [], 'partials' => [], 'syntaxError' => $e->getMessage()];
        }

        $depth = 0;
        foreach ($tokens as $token) {
            if (!is_array($token)) {
                continue;
            }

            $type = $token[\Mustache_Tokenizer::TYPE] ?? null;
            $name = $token[\Mustache_Tokenizer::NAME] ?? null;
            $line = (int) ($token[\Mustache_Tokenizer::LINE] ?? 0);

            if ($type === \Mustache_Tokenizer::T_PARTIAL && is_string($name)) {
                $partials[$name][] = $line;
                continue;
            }

            if ($type === \Mustache_Tokenizer::T_END_SECTION) {
                $depth = max(0, $depth - 1);
                continue;
            }

            $isSection = $type === \Mustache_Tokenizer::T_SECTION
                || $type === \Mustache_Tokenizer::T_INVERTED;
            $isVar = $isSection
                || $type === \Mustache_Tokenizer::T_ESCAPED
                || $type === \Mustache_Tokenizer::T_UNESCAPED
                || $type === \Mustache_Tokenizer::T_UNESCAPED_2;

            // Only root-level names are statically checkable: inside a section
            // the context is the section's item, and mustache falls back up
            // the context stack — flagging nested names would be guesswork.
            if ($isVar && $depth === 0 && is_string($name)) {
                $root = explode('.', $name)[0];
                if ($root !== '' && $root !== '.' && !Placeholders::isToken($root)) {
                    $vars[$root][] = $line;
                }
            }

            if ($isSection) {
                $depth++;
            }
        }

        return ['vars' => $vars, 'partials' => $partials, 'syntaxError' => null];
    }
}
