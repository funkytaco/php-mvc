<?php

declare(strict_types=1);

namespace Test\Nimbus\Tasks;

use Nimbus\Tasks\ContainerTask;
use PHPUnit\Framework\TestCase;

/**
 * Escaping out of an interactive app selector.
 *
 * A line-based prompt never sees Escape as a cancel key — mashing it puts raw
 * \x1b bytes into the answer. Those, an empty line, and quit words must all
 * read as "cancel", and real answers must never be mistaken for one.
 */
class CancelChoiceTest extends TestCase
{
    public function testEmptyAndNullInputCancel(): void
    {
        $this->assertTrue(ContainerTask::isCancelChoice(null));
        $this->assertTrue(ContainerTask::isCancelChoice(''));
        $this->assertTrue(ContainerTask::isCancelChoice('   '));
    }

    public function testMashedEscapeKeysCancel(): void
    {
        // Exactly what Esc-Esc-Esc… followed by Enter delivers
        $this->assertTrue(ContainerTask::isCancelChoice("\x1b\x1b\x1b\x1b"));
        $this->assertTrue(ContainerTask::isCancelChoice("\x1b"));
    }

    public function testQuitWordsCancel(): void
    {
        foreach (['q', 'Q', 'quit', 'cancel', 'exit', 'EXIT'] as $word) {
            $this->assertTrue(ContainerTask::isCancelChoice($word), $word);
        }
    }

    public function testRealSelectionsAreNotMistakenForCancel(): void
    {
        foreach (['1', '2', '10', 'all', 'foolio', '0'] as $answer) {
            $this->assertFalse(ContainerTask::isCancelChoice($answer), $answer);
        }
    }

    /**
     * Arrow keys also emit \x1b sequences. An answer with real characters in
     * it after a stray arrow press is not a cancel — the worst it should get
     * is "Invalid selection", never a silent bail-out.
     */
    public function testStrayControlSequencesAroundARealAnswerAreNotACancel(): void
    {
        $this->assertFalse(ContainerTask::isCancelChoice("\x1b[A2"));
    }
}
