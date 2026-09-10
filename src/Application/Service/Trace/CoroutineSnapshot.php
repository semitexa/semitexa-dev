<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * What the coroutines of THIS worker are doing right now, written to a small
 * per-worker file so the live panel can show every worker's coroutines side
 * by side — and, above all, the ones that have been running far too long.
 *
 * Coroutines are per process: a handler can only list its own worker's, so
 * each worker publishes its own snapshot and the reader merges them. The
 * writer is throttled per process and piggybacks on journal writes and feed
 * polls; a worker that neither serves a request nor answers the panel keeps
 * its last snapshot on disk, and the reader labels it by age.
 *
 * Long is not the same as hung: an SSE connection is a coroutine that is
 * SUPPOSED to live for hours. The snapshot carries the coroutine id, the
 * journal carries the id of every root process, and the panel joins the two
 * — a long coroutine with a live `sse` process behind it is a session, one
 * with an `http` process behind it is a stuck request, one with nothing
 * behind it is a leak.
 */
final class CoroutineSnapshot
{
    private const THROTTLE_MS = 2000;
    private const KEEP = 40;
    private const FRESH_SECONDS = 60;

    private static float $lastWriteMs = 0.0;

    public static function maybeWrite(): void
    {
        try {
            if (!class_exists(\Swoole\Coroutine::class, false) || !ObservatoryMode::full()) {
                return;
            }
            $now = microtime(true) * 1000;
            if ($now - self::$lastWriteMs < self::THROTTLE_MS) {
                return;
            }
            self::$lastWriteMs = $now;

            // The coroutine taking the snapshot is not load, it is the
            // measurement: a request that just began, a feed poll, a stream
            // tick. Counted, it made every idle worker show one coroutine
            // "busy" forever. Everything else that exists right now is real.
            $self = (int) \Swoole\Coroutine::getCid();
            $cids = [];
            foreach (\Swoole\Coroutine::listCoroutines() as $cid) {
                if ((int) $cid !== $self) {
                    $cids[] = (int) $cid;
                }
            }
            $rows = [];
            foreach ($cids as $cid) {
                $elapsed = (float) \Swoole\Coroutine::getElapsed($cid);
                $rows[] = ['cid' => $cid, 'ms' => round($elapsed, 1)];
            }
            usort($rows, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);
            $rows = array_slice($rows, 0, self::KEEP);
            foreach ($rows as &$row) {
                $row['frame'] = self::frame($row['cid']);
            }
            unset($row);

            $dir = ObservatoryJournal::dir() . '/coroutines';
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }
            // Occupancy against the pool: how many of the worker's coroutines
            // exist right now, the most it ever had, and the ceiling Swoole
            // will refuse beyond. The ceiling comes from the coroutine options
            // the server applied at worker start; the env value is the same
            // number one hop earlier, and Swoole's own default closes the gap.
            $stats = \Swoole\Coroutine::stats();
            [$max, $maxSource] = self::ceiling();
            $payload = json_encode([
                'ts' => date('c'),
                'pid' => getmypid(),
                'total' => count($cids),
                'num' => count($cids),
                // Swoole's peak includes every measuring coroutine there ever
                // was, so one is taken off: the floor of what the worker held
                // at its busiest, never below what is busy now.
                'peak' => max(count($cids), (int) ($stats['coroutine_peak_num'] ?? 0) - 1),
                'max' => $max,
                'maxSource' => $maxSource,
                'longest' => $rows,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                return;
            }
            // Temp + rename: a reader must never see half a snapshot.
            $final = $dir . '/' . getmypid() . '.json';
            $tmp = $final . '.tmp';
            if (@file_put_contents($tmp, $payload) !== false) {
                @rename($tmp, $final);
            }
        } catch (\Throwable) {
            // Observing must never fault the observed.
        }
    }

    /**
     * Every worker's fresh snapshot, oldest-coroutine first inside each.
     *
     * @return list<array{pid: int, ts: string, ageS: int, total: int, longest: list<array{cid: int, ms: float, frame: string}>}>
     */
    public static function readAll(): array
    {
        $out = [];
        $dir = ObservatoryJournal::dir() . '/coroutines';
        foreach (glob($dir . '/*.json') ?: [] as $file) {
            $row = json_decode((string) @file_get_contents($file), true);
            if (!is_array($row) || !isset($row['pid'], $row['ts'])) {
                continue;
            }
            $age = max(0, time() - (strtotime((string) $row['ts']) ?: time()));
            if ($age > self::FRESH_SECONDS) {
                // A worker that has gone quiet or died: its file is stale, not
                // evidence. Swept so a restarted stack does not show ghosts.
                @unlink($file);
                continue;
            }
            $out[] = [
                'pid' => (int) $row['pid'],
                'ts' => (string) $row['ts'],
                'ageS' => $age,
                'total' => (int) ($row['total'] ?? 0),
                'num' => (int) ($row['num'] ?? $row['total'] ?? 0),
                'peak' => (int) ($row['peak'] ?? 0),
                'max' => (int) ($row['max'] ?? 0),
                'maxSource' => (string) ($row['maxSource'] ?? ''),
                'longest' => is_array($row['longest'] ?? null) ? $row['longest'] : [],
            ];
        }
        usort($out, static fn (array $a, array $b): int => $a['pid'] <=> $b['pid']);

        return $out;
    }

    /** @return array{0: int, 1: string} the coroutine ceiling and where the number came from */
    private static function ceiling(): array
    {
        try {
            $options = \Swoole\Coroutine::getOptions();
            if (is_array($options) && isset($options['max_coroutine']) && (int) $options['max_coroutine'] > 0) {
                return [(int) $options['max_coroutine'], 'swoole options'];
            }
        } catch (\Throwable) {
        }
        $env = getenv('SWOOLE_MAX_COROUTINE');
        if (is_string($env) && ctype_digit(trim($env)) && (int) $env > 0) {
            return [(int) $env, 'SWOOLE_MAX_COROUTINE'];
        }

        return [100000, 'swoole default'];
    }

    /** The innermost frame that is not Swoole plumbing, as `Class::method` or `file:line`. */
    private static function frame(int $cid): string
    {
        try {
            $trace = \Swoole\Coroutine::getBackTrace($cid, DEBUG_BACKTRACE_IGNORE_ARGS, 6);
            if (!is_array($trace)) {
                return '';
            }
            foreach ($trace as $f) {
                $class = (string) ($f['class'] ?? '');
                // The observer's own frames are on the current coroutine's
                // stack; they say nothing about what that coroutine does.
                if ($class !== '' && !str_starts_with($class, 'Swoole\\') && !str_starts_with($class, __NAMESPACE__ . '\\')) {
                    return self::short($class) . '::' . (string) ($f['function'] ?? '');
                }
            }
            $f = $trace[0] ?? null;
            if (is_array($f)) {
                $fn = (string) ($f['function'] ?? '');
                if (isset($f['class'])) {
                    return self::short((string) $f['class']) . '::' . $fn;
                }

                return isset($f['file']) ? basename((string) $f['file']) . ':' . (int) ($f['line'] ?? 0) : $fn;
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
