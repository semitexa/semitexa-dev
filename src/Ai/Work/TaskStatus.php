<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

/**
 * Agent-facing task lifecycle. `blocked` is an explicit state (not just a
 * note on an in-progress task) so `ai:work list --status=blocked` produces
 * a useful inbox for a resuming agent. `discarded` is the junk-retirement
 * state so placeholders can be retired from default views without a hard
 * delete.
 *
 * Quality (clean / draft / junk) is a derived attribute computed by
 * {@see BacklogHygiene} and is NOT stored. Filtering combines stored status
 * with computed quality in {@see BacklogScope}.
 */
enum TaskStatus: string
{
    case NEW         = 'new';
    case IN_PROGRESS = 'in_progress';
    case BLOCKED     = 'blocked';
    case DONE        = 'done';
    case DISCARDED   = 'discarded';

    public static function parse(string $value): self
    {
        $status = self::tryFrom($value);
        if ($status === null) {
            throw new \InvalidArgumentException(
                "invalid task status '{$value}': expected one of " . implode(', ', self::all()),
            );
        }
        return $status;
    }

    public function isTerminal(): bool
    {
        return $this === self::DONE;
    }

    /**
     * True if the task is a candidate for the default human-facing backlog
     * (new / in_progress / blocked — blocked stays visible because it's
     * actionable: someone has to unblock it).
     */
    public function isActive(): bool
    {
        return $this === self::NEW || $this === self::IN_PROGRESS || $this === self::BLOCKED;
    }

    public function isHistorical(): bool
    {
        return $this === self::DONE;
    }

    public function isDiscarded(): bool
    {
        return $this === self::DISCARDED;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn(self $c) => $c->value, self::cases());
    }
}
