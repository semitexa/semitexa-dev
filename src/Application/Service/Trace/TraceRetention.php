<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Request traces (`var/trace/*.json`) kept as long as the Observatory journal
 * that links to them, then removed. Nothing removed them before: 9376 files
 * from five weeks of a dev stack on 2026-10-02, each a request's route, timing
 * and context, waiting to be picked up as "proof" (ep-agent-private-review-evidence).
 *
 * The file name starts with the day it was written (`Ymd-His-…`), so age is
 * read from the name, not from an mtime a copy or a touch can reset.
 */
final class TraceRetention
{
    public const DAYS = 7;

    /**
     * Files removed per call on the request path. The first sweep of a stack
     * that never had one met 9 000 files: a glob and thousands of unlinks in a
     * worker, none of them coroutine-hooked. A day's sweep is now a few
     * batches spread over the next requests.
     */
    public const BATCH = 500;

    /** @worker-scoped Directory => the day its sweep finished in this process. */
    private static array $sweptDay = [];

    /** Where request traces are written — the same rule RequestTracer follows. */
    public static function directory(): string
    {
        $configured = Environment::getEnvValue('SEMITEXA_TRACE_DIR');

        return is_string($configured) && $configured !== '' ? $configured : ProjectRoot::get() . '/var/trace';
    }

    /** Until the day's sweep is done, one batch per call; then nothing until tomorrow. */
    public static function sweepDaily(string $dir, ?int $now = null): void
    {
        $now ??= time();
        $today = date('Ymd', $now);
        if ((self::$sweptDay[$dir] ?? null) === $today) {
            return;
        }
        if (count(self::sweep($dir, $now, false, self::BATCH)) < self::BATCH) {
            self::$sweptDay[$dir] = $today;
        }
    }

    /**
     * @param int $limit at most this many files
     * @return list<string> the files removed (or, dry, that would be); one that
     *         could not be deleted is not in it
     */
    public static function sweep(string $dir, ?int $now = null, bool $dryRun = false, int $limit = PHP_INT_MAX): array
    {
        $cutoff = date('Ymd', ($now ?? time()) - self::DAYS * 86400);
        $removed = [];
        foreach (glob(rtrim($dir, '/') . '/*.json') ?: [] as $file) {
            if (count($removed) >= $limit) {
                break;
            }
            $day = substr(basename($file), 0, 8);
            if (preg_match('/^\d{8}$/', $day) === 1 && $day < $cutoff && ($dryRun || @unlink($file))) {
                $removed[] = $file;
            }
        }

        return $removed;
    }
}
