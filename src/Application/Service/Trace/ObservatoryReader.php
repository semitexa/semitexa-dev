<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * Folds the Observatory journal into the live process picture.
 *
 * The journal is the cross-worker medium (one NDJSON line per process begin /
 * end, written by every worker); this reader pairs the lines by process id and
 * answers the two questions the panel asks: what is running RIGHT NOW (a begin
 * with no end yet), and what just finished. Reading the file per poll instead
 * of holding subscriptions keeps the panel dependency-free and honest across
 * worker restarts — the file is the state, there is nothing to resync.
 */
#[AsService]
final class ObservatoryReader
{
    /**
     * How much journal tail one poll reads. A begin older than this window for
     * a STILL-OPEN process would make that process invisible, so the window is
     * generous — at ~200 bytes a line this is roughly the last 25k lifecycle
     * events, hours of a busy dev session.
     */
    private const TAIL_BYTES = 5_000_000;

    private const RECENT_LIMIT = 60;

    /**
     * A begin whose end never came AND whose age exceeds this is shown as
     * `stale`, not `live`: a worker killed mid-request never writes its end
     * line, and an SSE session legitimately runs for hours — the panel shows
     * both, labeled by age, and lets the human judge.
     */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Whether there is a journal to read here at all — dev, or monitor mode on
     * a production box. What a given SURFACE may show is a separate question:
     * the HTTP panel adds {@see ObservatoryPanelGate} on top of this, replay
     * demands {@see ObservatoryMode::full()}.
     */
    public function isEnabled(): bool
    {
        return ObservatoryMode::journals();
    }

    /**
     * @return array{
     *     generatedAt: string,
     *     live: list<array<string, mixed>>,
     *     recent: list<array<string, mixed>>,
     *     counts: array{live: int, stale: int, workers: int, byKind: array<string, int>},
     *     truncated: bool
     * }
     */
    public function snapshot(): array
    {
        $open = [];
        $recent = [];
        $truncated = false;

        foreach ($this->journalFiles() as $path) {
            [$lines, $cut] = $this->tailLines($path);
            $truncated = $truncated || $cut;

            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row) || !isset($row['event'], $row['id'])) {
                    continue;
                }

                if ($row['event'] === 'begin') {
                    $open[(string) $row['id']] = $row;
                    continue;
                }

                if ($row['event'] === 'end') {
                    // The begin may have fallen off the tail window; the end
                    // line carries kind/name/worker itself, so the recent list
                    // never depends on having seen the begin.
                    unset($open[(string) $row['id']]);
                    $recent[] = $row;
                }
            }
        }

        $now = time();
        $live = [];
        $staleCount = 0;
        foreach ($open as $row) {
            $startedAt = strtotime((string) ($row['ts'] ?? '')) ?: $now;
            $ageS = max(0, $now - $startedAt);
            $isStale = $ageS > self::STALE_AFTER_SECONDS;
            $staleCount += $isStale ? 1 : 0;
            $live[] = [
                'id' => $row['id'],
                'kind' => $row['kind'] ?? '?',
                'name' => $row['name'] ?? '?',
                'worker' => $row['worker'] ?? null,
                'startedTs' => $row['ts'] ?? null,
                'ageS' => $ageS,
                'stale' => $isStale,
            ];
        }
        // Oldest first: the process that has been running longest is the one a
        // stuck-request hunt is looking for.
        usort($live, static fn (array $a, array $b): int => $b['ageS'] <=> $a['ageS']);

        $recent = array_slice(array_reverse($recent), 0, self::RECENT_LIMIT);
        $recentOut = [];
        foreach ($recent as $row) {
            $recentOut[] = [
                'id' => $row['id'],
                'kind' => $row['kind'] ?? '?',
                'name' => $row['name'] ?? '?',
                'worker' => $row['worker'] ?? null,
                'endedTs' => $row['ts'] ?? null,
                'durationMs' => $row['durationMs'] ?? null,
                'trace' => $row['trace'] ?? null,
            ];
        }

        $byKind = [];
        foreach ($live as $p) {
            $byKind[$p['kind']] = ($byKind[$p['kind']] ?? 0) + 1;
        }

        return [
            'generatedAt' => date('c'),
            'live' => $live,
            'recent' => $recentOut,
            'counts' => [
                'live' => count($live),
                'stale' => $staleCount,
                'workers' => count(array_unique(array_filter(array_column($live, 'worker')))),
                'byKind' => $byKind,
            ],
            'truncated' => $truncated,
        ];
    }

    /**
     * How many rows a fresh page is handed before it starts following: enough
     * history to fill the ticker and the timeline, not enough to stall the
     * first paint.
     */
    private const BOOTSTRAP_ROWS = 300;

    /**
     * A cursor further behind than this is not caught up on, it is reset: a tab
     * that slept through an afternoon would otherwise be handed the whole
     * afternoon in one response, and the panel would animate it all at once.
     */
    private const CATCHUP_BYTES = 2_000_000;

    /**
     * The journal as a stream: every row appended since $cursor, and the cursor
     * to ask from next time. Cursor is `<day>:<byte offset>` into that day's
     * file, so following costs one stat and one bounded read per poll, and a
     * quiet poll reads nothing at all.
     *
     * A null, malformed or too-old cursor (or a file that shrank under it —
     * rotated, deleted, replaced) resets: the last {@see BOOTSTRAP_ROWS} rows
     * come back with `reset: true`, plus the current `live` list so a process
     * that began before those rows still shows as running.
     *
     * @return array{
     *     cursor: string,
     *     rows: list<array<string, mixed>>,
     *     reset: bool,
     *     live: list<array<string, mixed>>,
     *     coroutines: list<array<string, mixed>>,
     *     generatedAt: string
     * }
     */
    public function stream(?string $cursor): array
    {
        $today = date('Ymd');
        $todayPath = ObservatoryJournal::dir() . '/journal-' . $today . '.ndjson';

        $parsed = $this->parseCursor($cursor);
        if ($parsed === null) {
            return $this->bootstrap($todayPath, $today);
        }
        [$day, $offset] = $parsed;

        $chunks = [];
        if ($day !== $today) {
            // Date rolled over under the follower: finish yesterday's file
            // from where it stopped, then take today's from the top. Anything
            // older than yesterday is a stale tab, and a reset is kinder than
            // replaying a day.
            if ($day !== date('Ymd', time() - 86400)) {
                return $this->bootstrap($todayPath, $today);
            }
            $oldPath = ObservatoryJournal::dir() . '/journal-' . $day . '.ndjson';
            $oldSize = (int) (@filesize($oldPath) ?: 0);
            if ($oldSize > $offset) {
                $chunks[] = (string) @file_get_contents($oldPath, false, null, $offset, $oldSize - $offset);
            }
            $offset = 0;
        }

        clearstatcache(true, $todayPath);
        $size = (int) (@filesize($todayPath) ?: 0);
        if ($size < $offset) {
            return $this->bootstrap($todayPath, $today);
        }

        $pending = $size - $offset + array_sum(array_map('strlen', $chunks));
        if ($pending > self::CATCHUP_BYTES) {
            return $this->bootstrap($todayPath, $today);
        }

        $raw = $size > $offset
            ? (string) @file_get_contents($todayPath, false, null, $offset, $size - $offset)
            : '';

        // Only whole lines advance the cursor. A writer may be mid-line at the
        // moment of the read; its tail is left for the next poll rather than
        // decoded as broken JSON and lost.
        $lastNewline = strrpos($raw, "
");
        if ($lastNewline === false) {
            $raw = '';
            $next = $offset;
        } else {
            $next = $offset + $lastNewline + 1;
            $raw = substr($raw, 0, $lastNewline + 1);
        }
        $chunks[] = $raw;

        return [
            'cursor' => $today . ':' . $next,
            'rows' => $this->decodeRows(implode('', $chunks)),
            'reset' => false,
            'live' => [],
            'coroutines' => CoroutineSnapshot::readAll(),
            'generatedAt' => date('c'),
        ];
    }

    /** @return array{0: string, 1: int}|null */
    private function parseCursor(?string $cursor): ?array
    {
        if ($cursor === null || preg_match('/^(\d{8}):(\d{1,12})$/', $cursor, $m) !== 1) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    /**
     * @return array{cursor: string, rows: list<array<string, mixed>>, reset: bool, live: list<array<string, mixed>>, coroutines: list<array<string, mixed>>, generatedAt: string}
     */
    private function bootstrap(string $todayPath, string $today): array
    {
        clearstatcache(true, $todayPath);
        $size = (int) (@filesize($todayPath) ?: 0);
        [$lines] = $this->tailLines($todayPath);
        $rows = [];
        foreach (array_slice($lines, -self::BOOTSTRAP_ROWS) as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['event'], $row['id'])) {
                $rows[] = $row;
            }
        }

        return [
            'cursor' => $today . ':' . $size,
            'rows' => $rows,
            'reset' => true,
            'live' => $this->snapshot()['live'],
            'coroutines' => CoroutineSnapshot::readAll(),
            'generatedAt' => date('c'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function decodeRows(string $raw): array
    {
        $rows = [];
        foreach (explode("
", $raw) as $line) {
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['event'], $row['id'])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The last $limit journal records, chronological, optionally filtered.
     * This is `ai:observe tail` without --follow: raw journal rows, exactly
     * what is on disk, so the agent's mental model and the file never drift.
     *
     * @return list<array<string, mixed>>
     */
    public function tailRecords(int $limit, ?string $kind = null, ?string $nameContains = null): array
    {
        $rows = [];
        foreach ($this->journalFiles() as $path) {
            [$lines] = $this->tailLines($path);
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row)) {
                    continue;
                }
                if ($kind !== null && ($row['kind'] ?? '') !== $kind) {
                    continue;
                }
                if ($nameContains !== null && !str_contains((string) ($row['name'] ?? ''), $nameContains)) {
                    continue;
                }
                $rows[] = $row;
            }
        }

        return array_slice($rows, -$limit);
    }

    /**
     * Both lifecycle lines of one process, by id — the starting point of
     * `ai:observe show`.
     *
     * @return array{begin: array<string, mixed>|null, end: array<string, mixed>|null}
     */
    public function find(string $id): array
    {
        $begin = null;
        $end = null;
        foreach ($this->journalFiles() as $path) {
            [$lines] = $this->tailLines($path);
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (!is_array($row) || ($row['id'] ?? null) !== $id) {
                    continue;
                }
                if ($row['event'] === 'begin') {
                    $begin = $row;
                } elseif ($row['event'] === 'end') {
                    $end = $row;
                }
            }
        }

        return ['begin' => $begin, 'end' => $end];
    }

    /**
     * Today's journal file path — what `ai:observe tail --follow` watches. May
     * not exist yet on a quiet morning; the follower treats that as "empty so
     * far", not an error.
     */
    public function todayJournalPath(): string
    {
        return ObservatoryJournal::dir() . '/journal-' . date('Ymd') . '.ndjson';
    }

    /**
     * Today's journal, plus yesterday's when it exists — a process that began
     * before midnight must not vanish from the live list at date rollover.
     *
     * @return list<string>
     */
    private function journalFiles(): array
    {
        $dir = ObservatoryJournal::dir();
        $out = [];
        foreach ([date('Ymd', time() - 86400), date('Ymd')] as $day) {
            $path = $dir . '/journal-' . $day . '.ndjson';
            if (is_file($path)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * @return array{0: list<string>, 1: bool} lines and whether the head was cut off
     */
    private function tailLines(string $path): array
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            return [[], false];
        }

        $cut = $size > self::TAIL_BYTES;
        $raw = $cut
            ? (string) @file_get_contents($path, false, null, $size - self::TAIL_BYTES)
            : (string) @file_get_contents($path);

        $lines = explode("\n", $raw);
        if ($cut) {
            // The first chunk is almost certainly a half line; a half line is
            // not JSON and json_decode would reject it anyway — dropping it
            // here just makes that explicit.
            array_shift($lines);
        }

        return [array_values(array_filter($lines, static fn (string $l): bool => $l !== '')), $cut];
    }
}
