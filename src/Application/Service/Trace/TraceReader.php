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
            $root = $this->firstEvent($events, 'request', 'begin');
            $close = $this->firstEvent($events, 'request', 'end');
            $summary = $this->firstEvent($events, 'orm.summary', 'mark');

            $out[] = [
                'file' => basename($path),
                'recordedAt' => is_string($trace['recordedAt'] ?? null) ? $trace['recordedAt'] : '',
                'path' => (string) ($root['context']['path'] ?? '—'),
                'method' => (string) ($root['context']['method'] ?? ''),
                'totalMs' => (float) ($close['durationMs'] ?? 0.0),
                'queries' => (int) ($summary['context']['queries'] ?? 0),
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
     *     meta: array{file: string, recordedAt: string, path: string, method: string, route: string, totalMs: float},
     *     spans: list<array{name: string, depth: int, startMs: float, durationMs: float|null, context: array<string, mixed>}>,
     *     marks: list<array{name: string, atMs: float, context: array<string, mixed>}>,
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
        $root = $this->firstEvent($events, 'request', 'begin');
        $close = $this->firstEvent($events, 'request', 'end');

        $spans = [];
        $marks = [];
        $queries = [];
        /** @var array<string, array{name: string, depth: int, startMs: float, context: array<string, mixed>}> $open */
        $open = [];

        foreach ($events as $e) {
            $name = (string) ($e['name'] ?? '');
            $type = (string) ($e['type'] ?? '');

            if ($type === 'begin') {
                $open[$name] = [
                    'name' => $name,
                    'depth' => (int) ($e['depth'] ?? 0),
                    'startMs' => (float) ($e['atMs'] ?? 0.0),
                    'context' => is_array($e['context'] ?? null) ? $e['context'] : [],
                ];
                continue;
            }

            if ($type === 'end') {
                $started = $open[$name] ?? null;
                unset($open[$name]);
                $spans[] = [
                    'name' => $name,
                    'depth' => $started['depth'] ?? (int) ($e['depth'] ?? 0),
                    'startMs' => $started['startMs'] ?? (float) ($e['atMs'] ?? 0.0),
                    'durationMs' => isset($e['durationMs']) ? (float) $e['durationMs'] : null,
                    'context' => array_merge(
                        $started['context'] ?? [],
                        is_array($e['context'] ?? null) ? $e['context'] : [],
                    ),
                ];
                continue;
            }

            if ($type === 'query') {
                $ctx = is_array($e['context'] ?? null) ? $e['context'] : [];
                $queries[] = [
                    'sql' => (string) ($ctx['sql'] ?? ''),
                    'durationMs' => (float) ($e['durationMs'] ?? 0.0),
                    'params' => (int) ($ctx['params'] ?? 0),
                ];
                continue;
            }

            $marks[] = [
                'name' => $name,
                'atMs' => (float) ($e['atMs'] ?? 0.0),
                'context' => is_array($e['context'] ?? null) ? $e['context'] : [],
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
                'recordedAt' => is_string($trace['recordedAt'] ?? null) ? $trace['recordedAt'] : '',
                'path' => (string) ($root['context']['path'] ?? '—'),
                'method' => (string) ($root['context']['method'] ?? ''),
                'route' => (string) ($root['context']['route'] ?? ''),
                'totalMs' => (float) ($close['durationMs'] ?? 0.0),
            ],
            'spans' => $spans,
            'marks' => $marks,
            'queries' => $queries,
        ];
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

        return array_values($files);
    }

    /**
     * @return array{recordedAt?: mixed, events: list<array<string, mixed>>}|null
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

        /** @var array{recordedAt?: mixed, events: list<array<string, mixed>>} $data */
        return $data;
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
