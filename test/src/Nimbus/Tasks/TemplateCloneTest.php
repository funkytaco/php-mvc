<?php

declare(strict_types=1);

namespace Test\Nimbus\Tasks;

use Nimbus\Tasks\TemplateTask;
use Nimbus\Template\TemplateManager;
use PHPUnit\Framework\TestCase;

/**
 * nimbus:template-clone — a clone must be byte-identical to its source except
 * for the one load-bearing self-reference (app.nimbus.json's "type", which
 * routes nimbus:commit and feature scaffolding back to the template dir) plus
 * the template.json metadata it gains.
 */
class TemplateCloneTest extends TestCase
{
    private string $templatesDir;

    protected function setUp(): void
    {
        $this->templatesDir = sys_get_temp_dir() . '/test_nimbus_tplclone_' . uniqid();
        mkdir($this->templatesDir . '/fixture-src/Controllers', 0777, true);
        mkdir($this->templatesDir . '/fixture-src/Views/partials', 0777, true);

        file_put_contents(
            $this->templatesDir . '/fixture-src/app.nimbus.json',
            json_encode(['name' => '{{APP_NAME}}', 'type' => 'fixture-src', 'features' => ['database' => true]], JSON_PRETTY_PRINT)
        );
        file_put_contents($this->templatesDir . '/fixture-src/app.config.php', "<?php return ['installer-name' => '{{APP_NAME}}'];\n");
        file_put_contents($this->templatesDir . '/fixture-src/Controllers/IndexController.php', "<?php // controller\n");
        file_put_contents($this->templatesDir . '/fixture-src/Views/partials/nav.mustache', "<nav>{{app_name}}</nav>\n");
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->templatesDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->templatesDir);
    }

    private function clone(string $source, string $newName): bool
    {
        $task = new TemplateTask($this->templatesDir);

        ob_start();
        try {
            return $task->performClone($source, $newName);
        } finally {
            ob_end_clean();
        }
    }

    public function testCloneCopiesTheFullTreeAndIsDiscovered(): void
    {
        $this->assertTrue($this->clone('fixture-src', 'order-entry'));

        $this->assertFileExists($this->templatesDir . '/order-entry/app.config.php');
        $this->assertFileExists($this->templatesDir . '/order-entry/Controllers/IndexController.php');
        $this->assertFileExists($this->templatesDir . '/order-entry/Views/partials/nav.mustache');

        // Discovery is a directory scan — the clone must appear with no registration
        $this->assertArrayHasKey(
            'order-entry',
            (new TemplateManager($this->templatesDir))->getAvailableTemplates()
        );
    }

    public function testTypeFieldIsRewrittenToTheNewName(): void
    {
        $this->clone('fixture-src', 'order-entry');

        $config = json_decode((string) file_get_contents($this->templatesDir . '/order-entry/app.nimbus.json'), true);

        $this->assertSame('order-entry', $config['type']);
        // Everything else survives the round trip
        $this->assertSame('{{APP_NAME}}', $config['name']);
        $this->assertTrue($config['features']['database']);
    }

    public function testSourceTemplateIsUntouched(): void
    {
        $before = [];
        foreach (['app.nimbus.json', 'app.config.php', 'Controllers/IndexController.php'] as $file) {
            $before[$file] = file_get_contents($this->templatesDir . '/fixture-src/' . $file);
        }

        $this->clone('fixture-src', 'order-entry');

        foreach ($before as $file => $contents) {
            $this->assertSame($contents, file_get_contents($this->templatesDir . '/fixture-src/' . $file), $file);
        }
        $this->assertFileDoesNotExist($this->templatesDir . '/fixture-src/template.json');
    }

    public function testFreshTemplateJsonIsWrittenWithProvenance(): void
    {
        $this->clone('fixture-src', 'order-entry');

        $meta = json_decode((string) file_get_contents($this->templatesDir . '/order-entry/template.json'), true);

        $this->assertSame('order-entry', $meta['name']);
        $this->assertSame('fixture-src', $meta['cloned_from']);
        $this->assertSame('Cloned from fixture-src', $meta['description']);
    }

    public function testExistingTemplateJsonKeepsItsFieldsButGetsTheNewName(): void
    {
        file_put_contents(
            $this->templatesDir . '/fixture-src/template.json',
            json_encode(['name' => 'fixture-src', 'description' => 'Hand-written blurb', 'author' => 'neo'])
        );

        $this->clone('fixture-src', 'order-entry');

        $meta = json_decode((string) file_get_contents($this->templatesDir . '/order-entry/template.json'), true);

        $this->assertSame('order-entry', $meta['name']);
        $this->assertSame('Hand-written blurb', $meta['description']);
        $this->assertSame('neo', $meta['author']);
        $this->assertSame('fixture-src', $meta['cloned_from']);

        unlink($this->templatesDir . '/fixture-src/template.json');
    }

    public function testUnknownSourceIsRefused(): void
    {
        $this->assertFalse($this->clone('nope', 'order-entry'));
        $this->assertDirectoryDoesNotExist($this->templatesDir . '/order-entry');
    }

    public function testInvalidNamesAreRefused(): void
    {
        foreach (['Order-Entry', 'order entry', 'order_entry', 'order/entry', ''] as $bad) {
            $this->assertFalse($this->clone('fixture-src', $bad), "'$bad' should be refused");
        }
    }

    public function testExistingTargetIsRefusedAndLeftAlone(): void
    {
        mkdir($this->templatesDir . '/order-entry');
        file_put_contents($this->templatesDir . '/order-entry/keep.txt', 'precious');

        $this->assertFalse($this->clone('fixture-src', 'order-entry'));

        $this->assertSame('precious', file_get_contents($this->templatesDir . '/order-entry/keep.txt'));
        $this->assertFileDoesNotExist($this->templatesDir . '/order-entry/app.nimbus.json');
    }

    public function testRefusalWritesNothing(): void
    {
        $this->clone('fixture-src', 'Bad_Name');

        $entries = array_diff(scandir($this->templatesDir), ['.', '..']);

        $this->assertSame(['fixture-src'], array_values($entries));
    }
}
