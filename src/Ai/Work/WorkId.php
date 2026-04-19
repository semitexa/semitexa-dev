<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

/**
 * Shared validation for epic and task ids. The pattern matches TraceStore's
 * so a trace id and the task id that owns it are interchangeable slugs in
 * the same namespace — agents can pick `tk-fix-auth` and get both a task
 * file and a trace file with the same stem.
 *
 * Restrictive on purpose: this is the only name the filesystem sees. No
 * traversal, no spaces, no uppercase.
 */
final class WorkId
{
    public const PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';

    public static function assertValid(string $id, string $label = 'work id'): void
    {
        if (!preg_match(self::PATTERN, $id)) {
            throw new \InvalidArgumentException(
                "invalid {$label} '{$id}': must match " . self::PATTERN,
            );
        }
    }

    private function __construct() {}
}
