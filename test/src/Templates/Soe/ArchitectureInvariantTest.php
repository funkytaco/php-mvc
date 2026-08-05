<?php

declare(strict_types=1);

namespace Test\Templates\Soe;

use PHPUnit\Framework\TestCase;

/**
 * Machine-checkable form of the SOE Golden Rules (soe/AGENTS.md,
 * DESIGN-DD §13.3).
 *
 * These read the TEMPLATE SOURCE as text rather than instantiating anything,
 * because template code lives under the `App\` namespace which only autoloads
 * once an app has been generated into app/. Static analysis is also exactly
 * what DESIGN-DD §13.3 asks for.
 */
final class ArchitectureInvariantTest extends TestCase
{
    private const TEMPLATE = __DIR__ . '/../../../../.installer/_templates/soe';

    /**
     * GOLDEN RULE 2 — exactly one Helix write.
     *
     * `createTicket(` may appear only in the port that declares it and the one
     * service allowed to call it. A hit anywhere else means a second write
     * path was introduced.
     */
    public function testCreateTicketIsCalledFromOrderIntakeServiceOnly(): void
    {
        $allowed = [
            'Helix/HelixClientInterface.php',   // declares it
            'Helix/MockHelixClient.php',        // implements it
            'Services/OrderIntakeService.php',  // the ONLY caller
        ];

        $offenders = [];
        foreach ($this->phpFiles() as $relative => $source) {
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            if (str_contains($source, 'createTicket(')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Golden Rule 2 violated: createTicket() referenced outside OrderIntakeService in:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /** The port must expose exactly one mutator. */
    public function testHelixPortDeclaresOnlyOneMutator(): void
    {
        $port = (string) file_get_contents(self::TEMPLATE . '/Helix/HelixClientInterface.php');

        preg_match_all('/public function (\w+)\(/', $port, $matches);
        $methods = $matches[1];

        sort($methods);
        $this->assertSame(
            ['createTicket', 'getTicket', 'listTickets'],
            $methods,
            'The Helix port gained or lost a method. Only createTicket may mutate.'
        );
    }

    /**
     * GOLDEN RULE 3 — Helix owns fulfillment state.
     *
     * The app must not create tables that hold an authoritative copy of build
     * progress. `mock_helix_tickets` is the mock ADAPTER's storage standing in
     * for Helix itself, so it is explicitly allowed.
     */
    public function testSchemaDeclaresNoFulfillmentStateTables(): void
    {
        $schema = (string) file_get_contents(self::TEMPLATE . '/database/schema.sql');

        preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?\s+(\w+)/i', $schema, $matches);
        $tables = array_map('strtolower', $matches[1]);

        foreach (['nodes', 'queues', 'build_state'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $tables,
                "Golden Rule 3 violated: schema declares a `$forbidden` table. "
                . 'Fulfillment state belongs to Helix and is projected, never stored.'
            );
        }

        // Sanity: the tables DESIGN-DD §5.2 does require.
        foreach (['orders', 'sop_notes', 'audit_log', 'skus', 'catalog_items'] as $expected) {
            $this->assertContains($expected, $tables, "Expected table `$expected` is missing.");
        }
    }

    /**
     * GOLDEN RULE 4 — Helix credentials never leave the backend.
     *
     * Scoped to HELIX credentials specifically. Views/auth/configure.mustache
     * legitimately has a `client_secret` field: that is the operator entering
     * the app's own Keycloak client secret into an admin form, a different
     * credential under a different rule.
     */
    public function testNoHelixCredentialReachesAView(): void
    {
        foreach ($this->files(self::TEMPLATE . '/Views', 'mustache') as $relative => $source) {
            foreach (['auth_ref', 'HELIX_AUTH', 'helix_auth'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $source,
                    "Golden Rule 4 violated: Views/$relative references `$needle`."
                );
            }
        }
    }

    /**
     * No SOE surface may INTERPOLATE a secret.
     *
     * Checks mustache tags rather than raw text, so prose that merely names a
     * credential — "reveal the generated passwords with nimbus:vault-view" —
     * passes, while `{{keycloak_admin_password}}` fails. Rendering the value is
     * the leak; naming the concept is documentation.
     */
    public function testSurfaceViewsInterpolateNoSecretValue(): void
    {
        foreach ($this->files(self::TEMPLATE . '/Views', 'mustache') as $relative => $source) {
            // auth/ is the SSO configuration form — its job is to accept a secret.
            if (str_starts_with($relative, 'auth/')) {
                continue;
            }

            preg_match_all('/\{\{[{&]?\s*([\w.\-]+)\s*\}?\}\}/', $source, $matches);

            foreach ($matches[1] as $variable) {
                foreach (['password', 'secret', 'token', 'auth_ref'] as $needle) {
                    $this->assertStringNotContainsStringIgnoringCase(
                        $needle,
                        $variable,
                        "Views/$relative interpolates `{{{$variable}}}`, which looks like a secret."
                    );
                }
            }
        }
    }

    /**
     * GOLDEN RULE 6 — no SLA tracking.
     *
     * Elapsed time and blocker age are recorded for visibility only. Comments
     * are stripped first, so prose explaining the rule (e.g. "without
     * breaching the one-write rule") does not trip the check — only real code.
     */
    public function testNoSlaMachinery(): void
    {
        foreach ($this->phpFiles() as $relative => $source) {
            $code = $this->stripComments($source);

            foreach (['sla_target', 'slaTarget', 'slaBreach', 'escalate'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $needle,
                    $code,
                    "Golden Rule 6 violated: $relative looks like SLA machinery (`$needle`)."
                );
            }
        }
    }

    /**
     * The dev-only Helix progression must stay gated on app_env, or it ships
     * to production (DESIGN-DD §4).
     */
    public function testDevAdvanceEndpointIsEnvironmentGated(): void
    {
        $api = (string) file_get_contents(self::TEMPLATE . '/Controllers/ApiController.php');

        $advance = substr($api, (int) strpos($api, 'public function advance('));
        $this->assertStringContainsString(
            'isDemoEnv()',
            substr($advance, 0, 400),
            'POST /api/dev/helix/advance must check isDemoEnv() before doing anything.'
        );
    }

    /**
     * The bug that made the last build unusable: views link a stylesheet the
     * assets map never installs, so every page renders unstyled while still
     * returning 200 to a smoke test.
     *
     * The target must be `html/assets`, because the Apache docroot is
     * /var/www/html and the Dockerfile's final stage copies html/ but NOT
     * public/. Targeting `public` puts the file in the image at a path
     * nothing serves — the request then falls through .htaccess into
     * index.php, where the framework's asset route hands a Closure to a
     * dispatcher expecting [Class, method] and 500s.
     */
    public function testStylesheetIsInstalledIntoTheApacheDocroot(): void
    {
        $config = json_decode(
            (string) file_get_contents(self::TEMPLATE . '/app.nimbus.json'),
            true
        );

        $targets = array_column($config['assets'] ?? [], 'target');
        $this->assertContains(
            'html/assets',
            $targets,
            'app.nimbus.json must install static assets into html/assets (the Apache docroot). '
            . 'Any other target 404s or 500s at runtime while smoke tests still see HTTP 200.'
        );

        $this->assertFileExists(self::TEMPLATE . '/public/assets/css/soe.css');

        // Every view links this exact path, so the mapping and the href must agree.
        foreach ($this->files(self::TEMPLATE . '/Views', 'mustache') as $relative => $source) {
            if (str_contains($source, 'rel="stylesheet"')) {
                $this->assertStringContainsString(
                    'href="/assets/css/soe.css"',
                    $source,
                    "Views/$relative links a stylesheet at an unexpected path."
                );
            }
        }
    }

    /**
     * PostgreSQL reads "double quotes" as an identifier, so a double-quoted
     * LIKE pattern raises `column "..." does not exist` at runtime. This is a
     * real bug the previous build shipped twice.
     */
    public function testSqlStringLiteralsUseSingleQuotes(): void
    {
        foreach ($this->phpFiles() as $relative => $source) {
            // Comments are stripped so the docblock in MockHelixClient that
            // *explains* this hazard does not itself trip the check.
            $this->assertDoesNotMatchRegularExpression(
                '/LIKE\s+\\\\?"/i',
                $this->stripComments($source),
                "$relative uses a double-quoted LIKE pattern. PostgreSQL parses that as an "
                . 'identifier — use single quotes for string literals.'
            );
        }
    }

    /**
     * Remove PHP comments so invariant scans look at executable code only.
     * Uses the real tokenizer rather than a regex, so strings that happen to
     * contain comment markers survive intact.
     */
    private function stripComments(string $source): string
    {
        $out = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $token[1];
                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    /** @return array<string,string> relative path => source */
    private function phpFiles(): array
    {
        return $this->files(self::TEMPLATE, 'php');
    }

    /** @return array<string,string> relative path => source */
    private function files(string $root, string $extension): array
    {
        $this->assertDirectoryExists($root);

        $out = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== $extension) {
                continue;
            }
            $relative = str_replace($root . '/', '', $file->getPathname());
            $out[$relative] = (string) file_get_contents($file->getPathname());
        }

        return $out;
    }
}
