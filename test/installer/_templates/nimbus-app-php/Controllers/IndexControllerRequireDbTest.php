<?php

namespace Test\Installer\Templates\NimbusAppPhp\Controllers;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Tests IndexController::requireDb() — the guard that makes API endpoints
 * respond 503 instead of fataling when the app has no (reachable) database
 * (apps created with --no-db, or a database that is down).
 *
 * The controller under test is TEMPLATE source
 * (.installer/_templates/nimbus-app-php/), which is not on the autoload path
 * — App\ maps to the generated app/ dir, which may hold a stale copy of the
 * same class name. Each test therefore runs in its own process and requires
 * the template file explicitly, guaranteeing the template (the source of
 * truth) is what gets tested.
 *
 * Conventions per CLAUDE.md: no framework constants are defined in tests;
 * private members are reached via Reflection; the constructor (which needs
 * an Auryn injector and triggers initialize()) is bypassed with
 * newInstanceWithoutConstructor().
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class IndexControllerRequireDbTest extends TestCase
{
    private const TEMPLATE_DIR = __DIR__ . '/../../../../../.installer/_templates/nimbus-app-php';

    /**
     * Load the template classes and build a controller without running the
     * constructor (which would need a DI container and a live database).
     */
    private function makeController(): object
    {
        require_once self::TEMPLATE_DIR . '/Controllers/IndexController.php';

        return (new \ReflectionClass(\App\Controllers\IndexController::class))
            ->newInstanceWithoutConstructor();
    }

    private function invokeRequireDb(object $controller): array
    {
        $method = new \ReflectionMethod($controller, 'requireDb');
        $method->setAccessible(true);

        ob_start();
        $result = $method->invoke($controller);
        $output = ob_get_clean();

        return [$result, $output];
    }

    public function testRespondsServiceUnavailableWhenDatabaseIsAbsent(): void
    {
        $controller = $this->makeController();
        // $demoModel's declared default is null — exactly the state initialize()
        // leaves it in when the PDO connection fails (or --no-db). Nothing to set.

        [$result, $output] = $this->invokeRequireDb($controller);

        $this->assertFalse($result, 'requireDb() must report the database as unavailable');
        $this->assertSame(503, http_response_code(), 'guard must respond 503 Service Unavailable');

        $body = json_decode($output, true);
        $this->assertIsArray($body, 'guard must emit a JSON body');
        $this->assertSame('Database not available for this app', $body['error'] ?? null);
    }

    public function testPassesSilentlyWhenDatabaseIsPresent(): void
    {
        $controller = $this->makeController();

        require_once self::TEMPLATE_DIR . '/Models/DemoModel.php';
        $model = new \App\Models\DemoModel(new \PDO('sqlite::memory:'));

        $property = new \ReflectionProperty($controller, 'demoModel');
        $property->setAccessible(true);
        $property->setValue($controller, $model);

        [$result, $output] = $this->invokeRequireDb($controller);

        $this->assertTrue($result, 'requireDb() must pass when a model is present');
        $this->assertSame('', $output, 'guard must emit nothing on the happy path');
    }

    /**
     * Regression for the --no-db 500: apiList() used to call the model
     * unconditionally, fataling on null. With the guard it must return the
     * 503 JSON error and never touch the model.
     */
    public function testApiListIsGuardedWhenDatabaseIsAbsent(): void
    {
        $controller = $this->makeController();

        ob_start();
        $controller->apiList();
        $output = ob_get_clean();

        $this->assertSame(503, http_response_code());
        $body = json_decode($output, true);
        $this->assertSame('Database not available for this app', $body['error'] ?? null);
    }
}
