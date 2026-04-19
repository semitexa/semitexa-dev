<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

/**
 * Named view over the backlog. The default on `ai:epic list` / `ai:work
 * list` is {@see self::ACTIVE} — everything else is opt-in, so the human
 * view stays clean.
 *
 *   ACTIVE    — new / in_progress / blocked, with quality != junk
 *               (+ recently done epics ≤ DONE_RECENT_DAYS).
 *   DRAFTS    — quality = draft (regardless of status, unless discarded).
 *   DONE      — status = done, regardless of age.
 *   ARCHIVE   — status = archived.
 *   DISCARDED — status = discarded.
 *   JUNK      — quality = junk (regardless of status, unless discarded).
 *   ALL       — everything in the store.
 */
enum BacklogScope: string
{
    case ACTIVE    = 'active';
    case DRAFTS    = 'drafts';
    case DONE      = 'done';
    case ARCHIVE   = 'archive';
    case DISCARDED = 'discarded';
    case JUNK      = 'junk';
    case ALL       = 'all';

    public const int DONE_RECENT_DAYS = 14;

    public static function parse(string $value): self
    {
        $scope = self::tryFrom($value);
        if ($scope === null) {
            throw new \InvalidArgumentException(
                "invalid backlog scope '{$value}': expected one of " . implode(', ', self::all()),
            );
        }
        return $scope;
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(static fn(self $c) => $c->value, self::cases());
    }
}
