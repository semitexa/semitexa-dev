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

    /**
     * @worker-scoped The open append handle. Infrastructure, not request state:
     * it is the same file for every request this worker serves, so it is one of
     * the cases where a static is the right shape rather than a coroutine leak.
     * @var resource|null
     */
    private static $stream = null;

    /** @worker-scoped The path {@see $stream} points at. */
    private static string $streamPath = '';

    /**
     * @worker-scoped When the handle was last confirmed to still be the file at
     * {@see $streamPath}, as a unix timestamp.
     */
    private static int $streamCheckedAt = 0;

    /** @param array<string, mixed> $record */
    public static function write(array $record): void
    {
        try {
            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false || strlen($line) > self::MAX_LINE_BYTES) {
                return;
            }

            $dir = self::dir();
            $day = date('Ymd');
            $stream = self::stream($dir . '/journal-' . $day . '.ndjson');
            if ($stream === null) {
                return;
            }

            // flock keeps one line atomic across workers — the same guarantee
            // FILE_APPEND|LOCK_EX gave. What is gone is opening and closing the
            // file for EVERY line, which was the whole cost: on the bind-mounted
            // var/observatory, file_put_contents measured 8.94us against 2.30us
            // for this, and a root span writes two lines.
            //
            // fflush is not optional: file_put_contents wrote through, and the
            // panel reads the journal live. A buffered line is a line the live
            // view does not have yet.
            // ONE write inside the lock, exactly as file_put_contents did.
            // Buffering is switched off when the handle opens, so there is no
            // fflush to hold the lock across: a coroutine switching between
            // fwrite and fflush would leave every other coroutine in the worker
            // blocked on flock for the length of that gap.
            if (@flock($stream, LOCK_EX)) {
                @fwrite($stream, $line . "\n");
                @flock($stream, LOCK_UN);
            } else {
                // STILL WRITTEN when the lock cannot be taken, deliberately.
                // MAX_LINE_BYTES is 4000, under PIPE_BUF, and the handle is
                // opened append-only — so a single unbuffered write is atomic
                // on the filesystems this runs on. And the reader already skips
                // a line it cannot decode, so the worst case of a torn line is
                // one lost record. Dropping every line instead would lose all
                // of them to avoid losing one.
                @fwrite($stream, $line . "\n");
            }

            self::sweepOld($dir, $day);
        } catch (\Throwable) {
            // Observing must never fault the observed.
        }
    }

    /**
     * The append handle for one journal file, kept open for the worker.
     *
     * KEYED ON THE FULL PATH, not on the day. The day is the reason it rolls
     * over in production, but a test that puts a fresh SEMITEXA_OBSERVATORY_DIR
     * in the environment between cases changes the path without changing the
     * day — and a handle keyed on the day alone would keep writing into the
     * previous test's file. That is the static-state trap this class would
     * otherwise have walked into.
     *
     * @return resource|null null when the directory or file cannot be opened,
     *         which is not an error: observing must never fault the observed.
     */
    private static function stream(string $path)
    {
        if (self::$stream !== null && self::$streamPath === $path) {
            // A HELD HANDLE CAN OUTLIVE ITS FILE. sweepOld() only removes days
            // older than the retention window, never today's — but an operator
            // deleting the journal, or anything rotating it by rename, leaves
            // this writing into an unlinked inode where no reader will ever see
            // it again.
            //
            // Throttled on a CLOCK rather than checked per write: fstat costs
            // 2.07us against 3.80us for the whole write, so per-write it would
            // be the single most expensive thing here. Once a second bounds the
            // blind window without showing up in the measurement at all.
            $now = time();
            if ($now === self::$streamCheckedAt) {
                return self::$stream;
            }

            self::$streamCheckedAt = $now;
            $stat = @fstat(self::$stream);
            if (is_array($stat) && ($stat['nlink'] ?? 1) > 0) {
                return self::$stream;
            }
        }

        if (self::$stream !== null) {
            @fclose(self::$stream);
            self::$stream = null;
            self::$streamPath = '';
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }

        $handle = @fopen($path, 'ab');
        if ($handle === false) {
            return null;
        }

        // Unbuffered: the panel reads the journal LIVE, so a line sitting in a
        // PHP stream buffer is a line the live view does not have. This is what
        // file_put_contents gave for free, and it is also what lets the write
        // above be a single call inside the lock.
        @stream_set_write_buffer($handle, 0);

        self::$stream = $handle;
        self::$streamPath = $path;
        self::$streamCheckedAt = time();

        return $handle;
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
