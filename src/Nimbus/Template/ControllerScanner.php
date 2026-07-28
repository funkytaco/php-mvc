<?php

declare(strict_types=1);

namespace Nimbus\Template;

/**
 * Extracts ->render('view', [...]) calls from controller source without
 * executing anything (template routes/controllers may need a live DB).
 *
 * Handles both controller styles:
 *   - modern:  $this->render('demo/index', ['title' => ...])
 *   - legacy:  $this->renderer->render('index', $this->data)
 *
 * @phpstan-type RenderCall array{
 *   controller: string, method: string, view: string,
 *   keys: list<string>, resolvable: bool, file: string, line: int
 * }
 */
final class ControllerScanner
{
    /**
     * @return list<RenderCall>
     */
    public static function scan(string $phpSource, string $relPath): array
    {
        $tokens = token_get_all($phpSource);
        $count = count($tokens);
        $calls = [];

        $class = pathinfo($relPath, PATHINFO_FILENAME); // classless legacy files
        $method = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_CLASS) {
                $next = self::nextMeaningful($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $class = $tokens[$next][1];
                }
                continue;
            }

            if ($token[0] === T_FUNCTION) {
                $next = self::nextMeaningful($tokens, $i);
                if ($next !== null && is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                    $method = $tokens[$next][1];
                }
                continue;
            }

            // ->render( / ->renderTemplate( — the latter is the scaffold's
            // Slim-style helper name.
            if ($token[0] !== T_STRING || !in_array($token[1], ['render', 'renderTemplate'], true)) {
                continue;
            }
            $prev = self::prevMeaningful($tokens, $i);
            if ($prev === null || !is_array($tokens[$prev]) || $tokens[$prev][0] !== T_OBJECT_OPERATOR) {
                continue;
            }
            $open = self::nextMeaningful($tokens, $i);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            $args = self::splitArgs($tokens, $open, $end);
            $call = self::buildCall($args, $class, $method, $relPath, $token[2]);
            if ($call !== null) {
                $calls[] = $call;
            }
            $i = $end;
        }

        return $calls;
    }

    /**
     * Split the argument tokens of a call at top-level commas.
     * $open points at '('; $end receives the index of the matching ')'.
     *
     * @param list<array{int, string, int}|string> $tokens
     * @return list<list<array{int, string, int}|string>>
     */
    private static function splitArgs(array $tokens, int $open, ?int &$end): array
    {
        $args = [];
        $current = [];
        $depth = 1;
        $count = count($tokens);
        $end = $open;

        for ($i = $open + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;
            } elseif ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }

            if ($token === ',' && $depth === 1) {
                $args[] = $current;
                $current = [];
                continue;
            }

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }
            $current[] = $token;
        }

        if ($current) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * @param list<list<array{int, string, int}|string>> $args
     * @return array{controller: string, method: string, view: string, keys: list<string>, resolvable: bool, file: string, line: int}|null
     */
    private static function buildCall(array $args, string $class, string $method, string $file, int $line): ?array
    {
        // View name = first argument that is a lone string literal. This
        // transparently skips a leading $response in 3-arg legacy calls.
        $viewIndex = null;
        $view = null;
        foreach ($args as $index => $arg) {
            if (count($arg) === 1 && is_array($arg[0]) && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING) {
                $viewIndex = $index;
                $view = self::stripQuotes($arg[0][1]);
                break;
            }
        }
        if ($view === null || $view === '') {
            return null; // dynamic view name — nothing to check statically
        }

        $dataArg = $args[$viewIndex + 1] ?? null;
        $keys = [];
        $resolvable = false;

        if ($dataArg === null) {
            $resolvable = true; // render('view') with no data
        } elseif (self::isArrayLiteral($dataArg)) {
            $keys = self::topLevelKeys($dataArg);
            $resolvable = true;
        }
        // else: $this->data, $data, array_merge(...) — keys unknown

        return [
            'controller' => $class,
            'method' => $method,
            'view' => $view,
            'keys' => $keys,
            'resolvable' => $resolvable,
            'file' => $file,
            'line' => $line,
        ];
    }

    /**
     * @param list<array{int, string, int}|string> $arg
     */
    private static function isArrayLiteral(array $arg): bool
    {
        $first = $arg[0] ?? null;

        return $first === '[' || (is_array($first) && $first[0] === T_ARRAY);
    }

    /**
     * String keys at bracket depth 1 of an array literal: 'key' => ...
     *
     * @param list<array{int, string, int}|string> $arg
     * @return list<string>
     */
    private static function topLevelKeys(array $arg): array
    {
        $keys = [];
        $depth = 0;
        $count = count($arg);

        for ($i = 0; $i < $count; $i++) {
            $token = $arg[$i];

            if ($token === '[' || $token === '(' || $token === '{') {
                $depth++;
                continue;
            }
            if ($token === ']' || $token === ')' || $token === '}') {
                $depth--;
                continue;
            }

            // T_ARRAY's own '(' counts, so literal keys sit at depth 1 for
            // both [...] and array(...) forms.
            if ($depth === 1
                && is_array($token)
                && $token[0] === T_CONSTANT_ENCAPSED_STRING
                && ($arg[$i + 1] ?? null) !== null
                && is_array($arg[$i + 1])
                && $arg[$i + 1][0] === T_DOUBLE_ARROW
            ) {
                $keys[] = self::stripQuotes($token[1]);
            }
        }

        return array_values(array_unique($keys));
    }

    private static function stripQuotes(string $literal): string
    {
        return trim($literal, "'\"");
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        $count = count($tokens);
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }

        return null;
    }

    /**
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function prevMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            return $j;
        }

        return null;
    }
}
