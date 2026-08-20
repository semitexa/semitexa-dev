<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Append-only NDJSON journal of every process the framework runs — the warm
 * tier of the Observatory (`ep-observatory`, decisions in
 * var/docs/observatory-architecture.md).
 *
 * Unlike a trace file, which is one deliberately marked request in full detail,
 * the journal is one LINE per process lifecycle event for EVERY process in dev:
 * begin and end of each HTTP request, SSE session, and — as sources land —
 * scheduler jobs, queue consumers, timers. It is what `ai:observe ps/tail` and
 * the live dashboard read, so the format is consumer-facing: one JSON object
 * per line, no framing, tail-friendly.
 *
 * ## Why a file, not the SSE fan-out
 *
 * Workers are separate processes; anything cross-worker needs a shared medium
 * anyway. O_APPEND writes of bounded size are atomic between processes, survive
 * crashes mid-run, cost nothing when nobody reads them, and are consumable by
 * the AI with `tail` semantics — no daemon, no subscription state. Live push
 * over the KISS transport is an upgrade the UI task adds ON TOP of this,
 * never instead of it.
 *
 * ## Cost discipline
 *
 * Gated by the caller ({@see ObservatoryMode} — the tracer announces nothing
 * when the mode is off, and samples in monitor mode), and every write is
 * @-guarded and wrapped: a diagnostic that can fail the process it observes is
 * worse than none. Files roll per day so a long-lived stack does not grow one
 * unbounded file.
 */
final class ObservatoryJournal
{
    /**
     * One line must stay one atomic O_APPEND write. POSIX guarantees that only
     * up to PIPE_BUF-ish sizes; records are scrubbed summaries, so anything
     * larger than this is a bug upstream and is dropped rather than interleaved.
     */
    private const MAX_LINE_BYTES = 4000;

    /** Journal files older than this are swept on the first write of a new day. */
    private const RETENTION_DAYS = 7;

    /** The day whose sweep already ran in this process, so retention costs one glob per day. */
    private static ?string $sweptDay = null;

    /** @param array<string, mixed> $record */
    public static function write(array $record): void
    {
        try {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false || strlen($line) > self::MAX_LINE_BYTES) {
                return;
            }

            $dir = self::dir();
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }

            $day = date('Ymd');
            @file_put_contents(
                $dir . '/journal-' . $day . '.ndjson',
                $line . "\n",
                FILE_APPEND | LOCK_EX,
            );

            self::sweepOld($dir, $day);
        } catch (\Throwable) {
            // Observing must never fault the observed.
        }
    }

    /**
     * Unique across workers: the worker pid disambiguates identical random
     * suffixes, and makes "which worker ran this" free in every consumer.
     */
    public static function newProcessId(): string
    {
        return 'p-' . getmypid() . '-' . bin2hex(random_bytes(4));
    }

    public static function dir(): string
    {
        $configured = Environment::getEnvValue('SEMITEXA_OBSERVATORY_DIR');

        return is_string($configured) && $configured !== ''
            ? $configured
            : ProjectRoot::get() . '/var/observatory';
    }

    /**
     * Delete journal files older than the retention window. Runs once per
     * process per day (memoized on the day string), so a busy worker pays one
     * glob a day and an idle one pays nothing. Filenames carry their day, so
     * age needs no stat calls — string comparison on the suffix is enough.
     */
    private static function sweepOld(string $dir, string $today): void
    {
        if (self::$sweptDay === $today) {
            return;
        }
        self::$sweptDay = $today;

        $cutoff = date('Ymd', time() - self::RETENTION_DAYS * 86400);
        foreach (glob($dir . '/journal-*.ndjson') ?: [] as $file) {
            $day = substr(basename($file), 8, 8);
            if ($day !== '' && $day < $cutoff) {
                @unlink($file);
            }
        }
    }
}
