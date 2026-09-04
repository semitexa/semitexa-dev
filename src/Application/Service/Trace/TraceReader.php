<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * Reads recorded traces off disk and turns the flat event log into spans.
 *
 * The stored format is flat — a list of begin/end/mark/query events carrying a
 * depth — because a nested structure cannot represent a span that never closed,
 * and a request that died mid-flight is exactly the one worth looking at. Pairing
 * them up is this class's job, and an unclosed span survives that pairing with a
 * null duration rather than being dropped.
 */
#[AsService]
final class TraceReader
{
    /**
     * Newest first — a developer opens the viewer right after making the request
     * they care about.
     *
     * @return list<array{file: string, recordedAt: string, path: string, method: string, totalMs: float, queries: int}>
     */
    public function list(int $limit = 50): array
    {
        $out = [];
        foreach ($this->files() as $path) {
            $trace = $this->decode($path);
            if ($trace === null) {
                continue;
            }

            $events = $trace['events'];
            $root = $this->rootEvent($events, 'begin');
            $close = $this->rootEvent($events, 'end');
            $summary = $this->firstEvent($events, 'orm.summary', 'mark');

            $out[] = [
                'file' => basename($path),
                'recordedAt' => $this->str($trace, 'recordedAt'),
                'path' => $this->str($this->arr($root, 'context'), 'path', '—'),
                'method' => $this->str($this->arr($root, 'context'), 'method', $this->str($root, 'name') === 'sse' ? 'SSE' : ''),
                'totalMs' => $close !== [] ? $this->float($close, 'durationMs') : $this->float($trace, 'totalMs'),
                'queries' => $this->int($this->arr($summary, 'context'), 'queries'),
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * One trace, resolved into spans, marks and queries.
     *
     * @return array{
     *     meta: array{file: string, recordedAt: string, path: string, method: string, route: string, totalMs: float, truncated: bool},
     *     spans: list<array<string, mixed>>,
     *     marks: list<array<string, mixed>>,
     *     queries: list<array{sql: string, durationMs: float, params: int}>
     * }|null
     */
    public function read(string $file): ?array
    {
        // basename() only: the file name arrives from a query string, and this
        // reads from disk. Anything with a path in it must not survive.
        $path = $this->dir() . '/' . basename($file);
        $trace = $this->decode($path);
        if ($trace === null) {
            return null;
        }

        $events = $trace['events'];
        $root = $this->rootEvent($events, 'begin');
        $close = $this->rootEvent($events, 'end');

        $spans = [];
        $marks = [];
        $queries = [];
        /** @var array<string, array<string, mixed>> $open */
        $open = [];

        foreach ($events as $e) {
            $name = $this->str($e, 'name');
            $type = $this->str($e, 'type');
            // Keyed by coroutine as well as name: an SSE trace runs several
            // coroutines at once, and two of them opening `pipeline` would
            // otherwise pair the first begin with the second end.
            $key = $this->int($e, 'cid') . '|' . $name;

            if ($type === 'begin') {
                $open[$key] = [
                    'name' => $name,
                    'depth' => $this->int($e, 'depth'),
                    'startMs' => $this->float($e, 'atMs'),
                    'cid' => $this->int($e, 'cid'),
                    'pcid' => $this->int($e, 'pcid'),
                    'context' => $this->arr($e, 'context'),
                ];
                continue;
            }

            if ($type === 'end') {
                $started = $open[$key] ?? null;
                unset($open[$key]);
                $spans[] = [
                    'name' => $name,
                    'depth' => $started['depth'] ?? $this->int($e, 'depth'),
                    'startMs' => $started['startMs'] ?? $this->float($e, 'atMs'),
                    'cid' => $started['cid'] ?? $this->int($e, 'cid'),
                    'pcid' => $started['pcid'] ?? $this->int($e, 'pcid'),
                    'durationMs' => isset($e['durationMs']) ? $this->float($e, 'durationMs') : null,
                    'context' => array_merge(
                        $started !== null ? $this->arr($started, 'context') : [],
                        $this->arr($e, 'context'),
                    ),
                ];
                continue;
            }

            if ($type === 'query') {
                $ctx = $this->arr($e, 'context');
                $queries[] = [
                    'sql' => $this->str($ctx, 'sql'),
                    'durationMs' => $this->float($e, 'durationMs'),
                    'params' => $this->int($ctx, 'params'),
                    // Position on the timeline; null for traces recorded before
                    // queries were attached live (the drained list had none).
                    'atMs' => isset($e['atMs']) && (is_float($e['atMs']) || is_int($e['atMs'])) ? (float) $e['atMs'] : null,
                ];
                continue;
            }

            $marks[] = [
                'name' => $name,
                'atMs' => $this->float($e, 'atMs'),
                'cid' => $this->int($e, 'cid'),
                'pcid' => $this->int($e, 'pcid'),
                'context' => $this->arr($e, 'context'),
            ];
        }

        // A span still open at the end never closed — the request died inside it.
        // Kept, with a null duration, because that is the interesting case.
        foreach ($open as $started) {
            $spans[] = $started + ['durationMs' => null];
        }

        usort($spans, static fn (array $a, array $b): int => $a['startMs'] <=> $b['startMs']);

        return [
            'meta' => [
                'file' => basename($file),
                'recordedAt' => $this->str($trace, 'recordedAt'),
                'path' => $this->str($this->arr($root, 'context'), 'path', '—'),
                'method' => $this->str($this->arr($root, 'context'), 'method'),
                'route' => $this->str($this->arr($root, 'context'), 'route'),
                // The end event carries the authoritative duration, but the event
                // cap can drop it. The recorder writes elapsed time outside the
                // capped list for exactly that case.
                'totalMs' => $close !== [] ? $this->float($close, 'durationMs') : $this->float($trace, 'totalMs'),
                'truncated' => ($trace['truncated'] ?? false) === true,
            ],
            'spans' => $spans,
            'marks' => $marks,
            'queries' => $queries,
        ];
    }

    /** @param array<string, mixed> $a */
    private function str(array $a, string $k, string $default = ''): string
    {
        $v = $a[$k] ?? null;

        return is_string($v) ? $v : $default;
    }

    /** @param array<string, mixed> $a */
    private function int(array $a, string $k): int
    {
        $v = $a[$k] ?? null;

        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : 0);
    }

    /** @param array<string, mixed> $a */
    private function float(array $a, string $k): float
    {
        $v = $a[$k] ?? null;

        return is_float($v) || is_int($v) ? (float) $v : (is_numeric($v) ? (float) $v : 0.0);
    }

    /**
     * @param  array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function arr(array $a, string $k): array
    {
        $v = $a[$k] ?? null;
        if (!is_array($v)) {
            return [];
        }

        /** @var array<string, mixed> $v */
        return $v;
    }

    public function isEnabled(): bool
    {
        return Environment::getEnvValue('APP_ENV') === 'dev';
    }

    public function dir(): string
    {
        $configured = Environment::getEnvValue('SEMITEXA_TRACE_DIR');

        return is_string($configured) && $configured !== ''
            ? $configured
            : ProjectRoot::get() . '/var/trace';
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob($this->dir() . '/*.json') ?: [];
        rsort($files);

        return $files;
    }

    /**
     * @return array{recordedAt?: mixed, truncated?: mixed, totalMs?: mixed, events: list<array<string, mixed>>}|null
     */
    private function decode(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['events'] ?? null)) {
            return null;
        }

        /** @var array{recordedAt?: mixed, truncated?: mixed, totalMs?: mixed, events: list<array<string, mixed>>} $data */
        return $data;
    }

    /**
     * The span that opened the trace. Two names can: a page request and an SSE
     * connection, which is its own trace rather than a continuation of the page
     * that minted its deferred id.
     *
     * @param  list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function rootEvent(array $events, string $type): array
    {
        foreach (['request', 'sse'] as $name) {
            $found = $this->firstEvent($events, $name, $type);
            if ($found !== []) {
                return $found;
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>> $events
     * @return array<string, mixed>
     */
    private function firstEvent(array $events, string $name, string $type): array
    {
        foreach ($events as $e) {
            if (($e['name'] ?? null) === $name && ($e['type'] ?? null) === $type) {
                return $e;
            }
        }

        return [];
    }
}
