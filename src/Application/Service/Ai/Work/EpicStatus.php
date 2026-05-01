<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Epic lifecycle. Five states — the fifth (`discarded`) is an explicit
 * marker for intentionally junked entries, so the hygiene workflow can
 * retire placeholders without a hard delete that would lose audit trail.
 *
 * Quality (clean / draft / junk) is a derived attribute computed by
 * {@see BacklogHygiene} and is NOT stored. Filtering combines stored status
 * with computed quality in {@see BacklogScope}.
 */
enum EpicStatus: string
{
    case NEW         = 'new';
    case IN_PROGRESS = 'in_progress';
    case DONE        = 'done';
    case ARCHIVED    = 'archived';
    case DISCARDED   = 'discarded';

    public static function parse(string $value): self
    {
        $status = self::tryFrom($value);
        if ($status === null) {
            throw new \InvalidArgumentException(
                "invalid epic status '{$value}': expected one of " . implode(', ', self::all()),
            );
        }
        return $status;
    }

    /** True if the epic is a candidate for the default human-facing backlog. */
    public function isActive(): bool
    {
        return $this === self::NEW || $this === self::IN_PROGRESS;
    }

    /** True if the epic is historical (done or archived) — excluded from the active view. */
    public function isHistorical(): bool
    {
        return $this === self::DONE || $this === self::ARCHIVED;
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
