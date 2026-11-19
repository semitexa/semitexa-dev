<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The backlog is not code: `var/ai-work/**` and `var/ai-traces/**`, relative to
 * the project root.
 *
 * Every `ai:work` call changes those files and a close rewrites them, so a
 * changed-file set that held them proved nothing it could keep. Measured
 * 2026-11-19 on a fresh project: a bare `ai:verify --trace=<task>` collected 24
 * changed files, 11 of them the backlog. That pushed the run past the broad
 * threshold, and the close that followed rewrote the task file the receipt had
 * fingerprinted, so the task was counted unproven
 * (tk-verify-counts-the-backlog-as-changed-files).
 *
 * So what ai:verify COLLECTS (the dirty tree, a ref's diff, a task's commits)
 * leaves the backlog out, and the push gate does not ask a run to have read
 * it. A path the caller names, with `--files` or on `--diff-stdin`, stays as asked.
 */
final class BacklogPaths
{
    private const ROOTS = ['var/ai-work', 'var/ai-traces'];

    /** @param string $path relative to the project root, as the scanner writes it */
    public static function holds(string $path): bool
    {
        $path = UnselectedChanges::normalize($path);
        foreach (self::ROOTS as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A file renamed INTO the backlog is one entry, named by where it went: its
     * source is code that is gone, so it stays as a deletion (review, dev#288).
     * A rename inside the backlog is dropped whole.
     *
     * @template T of array{path: string, status: string, originalPath?: string}
     * @param list<T> $entries
     * @return list<T|array{path: string, status: string}>
     */
    public static function without(array $entries): array
    {
        $kept = [];
        foreach ($entries as $entry) {
            if (!self::holds($entry['path'])) {
                $kept[] = $entry;
                continue;
            }
            $source = $entry['originalPath'] ?? '';
            if ($source !== '' && !self::holds($source)) {
                $kept[] = ['path' => $source, 'status' => ChangedFile::STATUS_DELETED];
            }
        }

        return $kept;
    }
}
