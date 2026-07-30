<?php

declare(strict_types=1);

namespace Nimbus\UI;

/**
 * An ordered list of commands where exactly one is marked as the thing to run
 * next.
 *
 * Printing every command a user *could* run leaves them working out which one
 * they *have* to run — "do I still need install, or can I skip to up?". Each
 * step carries whether it is already satisfied; the first unsatisfied one is
 * the next action, anything before it is shown as done, and anything after it
 * is shown as not yet actionable.
 *
 * Deliberately decoupled from any manager: callers decide what "satisfied"
 * means for their own steps. Currently wired into the git add-database flow;
 * the other commands still print a plain list.
 */
final class StepList
{
    private const GREEN = "\033[0;32m";
    private const CYAN = "\033[1;36m";
    private const DIM = "\033[0;90m";
    private const RESET = "\033[0m";

    /** @var list<array{command: string, note: ?string, satisfied: bool, optional: bool}> */
    private array $steps = [];

    /**
     * @param bool $satisfied whether this step's outcome is already true
     * @param bool $optional  a step that never blocks the ones after it
     */
    public function add(string $command, bool $satisfied, ?string $note = null, bool $optional = false): self
    {
        $this->steps[] = [
            'command' => $command,
            'note' => $note,
            'satisfied' => $satisfied,
            'optional' => $optional,
        ];

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    /**
     * True when nothing required is left to do.
     */
    public function isComplete(): bool
    {
        foreach ($this->steps as $step) {
            if (!$step['satisfied'] && !$step['optional']) {
                return false;
            }
        }

        return true;
    }

    public function render(): string
    {
        if ($this->steps === []) {
            return '';
        }

        $width = max(array_map(
            static fn (array $step): int => strlen($step['command']),
            $this->steps
        ));

        $out = '';
        $nextTaken = false;

        foreach ($this->steps as $step) {
            $isNext = !$nextTaken && !$step['satisfied'] && !$step['optional'];
            if ($isNext) {
                $nextTaken = true;
            }

            if ($step['satisfied']) {
                $marker = self::GREEN . '✓' . self::RESET;
                $body = self::DIM . str_pad($step['command'], $width) . self::RESET;
                $note = $step['note'] !== null ? self::DIM . '  ' . $step['note'] . self::RESET : '';
            } elseif ($isNext) {
                $marker = self::CYAN . '→' . self::RESET;
                $body = self::CYAN . str_pad($step['command'], $width) . self::RESET;
                $note = self::CYAN . '  ← run this next' . self::RESET;
            } else {
                $marker = ' ';
                $body = str_pad($step['command'], $width);
                $note = $step['note'] !== null ? self::DIM . '  ' . $step['note'] . self::RESET : '';
            }

            $out .= '  ' . $marker . ' ' . $body . $note . PHP_EOL;
        }

        return $out;
    }
}
