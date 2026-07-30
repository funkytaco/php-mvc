<?php

declare(strict_types=1);

namespace Test\Nimbus\UI;

use Nimbus\UI\StepList;
use PHPUnit\Framework\TestCase;

class StepListTest extends TestCase
{
    /** Rendering carries ANSI colour; assertions read the text underneath. */
    private function plain(StepList $steps): string
    {
        return preg_replace('/\033\[[0-9;]*m/', '', $steps->render()) ?? '';
    }

    public function testExactlyOneStepIsMarkedAsNext(): void
    {
        $steps = (new StepList())
            ->add('composer nimbus:install app', true)
            ->add('composer nimbus:up app', false)
            ->add('composer nimbus:scan app', false);

        $this->assertSame(1, substr_count($this->plain($steps), '← run this next'));
    }

    public function testTheNextStepIsTheFirstUnsatisfiedOne(): void
    {
        $steps = (new StepList())
            ->add('first', true)
            ->add('second', false)
            ->add('third', false);

        $lines = array_values(array_filter(explode("\n", $this->plain($steps))));

        $this->assertStringContainsString('✓', $lines[0]);
        $this->assertStringContainsString('← run this next', $lines[1]);
        $this->assertStringNotContainsString('← run this next', $lines[2]);
    }

    public function testSatisfiedStepsAreMarkedDoneWithTheirNote(): void
    {
        $steps = (new StepList())->add('composer nimbus:install app', true, 'compose and .env are current');

        $plain = $this->plain($steps);

        $this->assertStringContainsString('✓', $plain);
        $this->assertStringContainsString('compose and .env are current', $plain);
        $this->assertStringNotContainsString('← run this next', $plain);
    }

    /**
     * An optional step must not absorb the "next" marker from a required one
     * that follows it.
     */
    public function testOptionalStepsNeverBecomeTheNextAction(): void
    {
        $steps = (new StepList())
            ->add('bin/nimbus dev app', false, 'optional', true)
            ->add('composer nimbus:up app', false);

        $lines = array_values(array_filter(explode("\n", $this->plain($steps))));

        $this->assertStringNotContainsString('← run this next', $lines[0]);
        $this->assertStringContainsString('← run this next', $lines[1]);
    }

    public function testCompleteWhenEveryRequiredStepIsSatisfied(): void
    {
        $this->assertTrue((new StepList())->add('a', true)->add('b', true)->isComplete());
        $this->assertFalse((new StepList())->add('a', true)->add('b', false)->isComplete());

        // An unsatisfied optional step does not make the list incomplete
        $this->assertTrue((new StepList())->add('a', true)->add('b', false, null, true)->isComplete());
    }

    public function testNothingLeftToDoShowsNoNextMarker(): void
    {
        $steps = (new StepList())->add('a', true)->add('b', true);

        $this->assertStringNotContainsString('← run this next', $this->plain($steps));
    }

    public function testEmptyListRendersNothing(): void
    {
        $steps = new StepList();

        $this->assertTrue($steps->isEmpty());
        $this->assertSame('', $steps->render());
    }

    public function testCommandsAreAlignedIntoAColumn(): void
    {
        // Both satisfied, so both render their own note rather than one of
        // them being replaced by the "next" marker.
        $steps = (new StepList())
            ->add('short', true, 'NOTE_ONE')
            ->add('a-much-longer-command', true, 'NOTE_TWO');

        $lines = array_values(array_filter(explode("\n", $this->plain($steps))));

        $this->assertSame(
            strpos($lines[0], 'NOTE_ONE'),
            strpos($lines[1], 'NOTE_TWO'),
            'notes should start at the same column'
        );
    }
}
