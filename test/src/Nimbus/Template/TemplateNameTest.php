<?php

declare(strict_types=1);

namespace Test\Nimbus\Template;

use Nimbus\Template\TemplateManager;
use PHPUnit\Framework\TestCase;

/**
 * The template-name vocabulary shared by nimbus:template-scaffold and
 * nimbus:template-clone — the counterpart of AppManager's app-name rule.
 */
class TemplateNameTest extends TestCase
{
    public function testEveryExistingTemplateNamePasses(): void
    {
        foreach (['lkui', 'nimbus-app-php', 'sandbox', 'order-entry'] as $name) {
            $this->assertNull(TemplateManager::templateNameError($name), $name);
        }
    }

    public function testInvalidNamesAreExplained(): void
    {
        foreach (['Order-Entry', 'order entry', 'order_entry', 'order.entry', '-order', 'order-', ''] as $bad) {
            $this->assertNotNull(TemplateManager::templateNameError($bad), "'$bad' should be rejected");
        }
    }

    public function testSuggestionsAreCloseAndAlwaysValid(): void
    {
        $this->assertSame('order-entry', TemplateManager::suggestTemplateName('Order Entry'));
        $this->assertSame('order-entry', TemplateManager::suggestTemplateName('order_entry'));
        $this->assertSame('order-entry', TemplateManager::suggestTemplateName('-order.entry-'));

        foreach (['Order Entry!!', '™©', '', '   ', 'a b c', str_repeat('-', 5)] as $input) {
            $suggestion = TemplateManager::suggestTemplateName($input);
            $this->assertNull(
                TemplateManager::templateNameError($suggestion),
                "suggestion '$suggestion' for '$input' must itself be valid"
            );
        }
    }

    public function testAppNameSuggestionsAreAlsoAlwaysValid(): void
    {
        // Same contract on the app-name side (AppManager::suggestAppName)
        foreach (['test orders', 'Test Orders!', 'lkui', '-lead', str_repeat('x', 60) . ' app', '™'] as $input) {
            $suggestion = \Nimbus\App\AppManager::suggestAppName($input);
            $this->assertNull(
                \Nimbus\App\AppManager::appNameError($suggestion),
                "suggestion '$suggestion' for '$input' must itself be valid"
            );
        }

        $this->assertSame('test-orders', \Nimbus\App\AppManager::suggestAppName('test orders'));
        $this->assertSame('lkui-app', \Nimbus\App\AppManager::suggestAppName('lkui'), 'reserved names get a suffix');
    }
}
