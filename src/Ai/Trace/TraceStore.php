<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Support\ProjectRoot;

/**
 * File-backed NDJSON trace store under `<projectRoot>/var/ai-traces/`.
 *
 *   One trace = one file `<trace_id>.ndjson`:
 *     line 1      header ({"kind":"header", ...})
 *     line 2..N   events ({"kind":"event", event_id:1..}, ...)
 *
 * Appends use `LOCK_EX | FILE_APPEND` so concurrent writers from different
 * commands in the same worker don't tear lines. Reads are full-file scans —
 * fine for the sizes we expect (a trace per feature/task, rarely hundreds of
 * events). If traces get huge we can add an index; we don't speculate.
 *
 * Trace IDs are validated against a safe pattern. This is the only name the
 * filesystem sees, so we keep it restrictive: lowercase/digits/`-`/`_`, 1–64
 * chars, must start alphanumeric.
 *
 * Container-managed (#[AsService]) so this type is injectable via
 * #[InjectAsReadonly]. Project root is resolved lazily from
 * {@see ProjectRoot::get()} — tests chdir into a fixture dir and call
 * `ProjectRoot::reset()` to isolate the filesystem.
 */
#[AsService]
final class TraceStore
{
    private const ID_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/';
    private const SUBDIR     = 'var/ai-traces';

    public static function assertValidId(string $traceId): void
    {
        if (!preg_match(self::ID_PATTERN, $traceId)) {
            throw new \InvalidArgumentException(
                "invalid trace id '{$traceId}': must match " . self::ID_PATTERN,
            );
        }
    }

    public function exists(string $traceId): bool
    {
        self::assertValidId($traceId);
        return is_file($this->pathFor($traceId));
    }

    /**
     * Idempotent. If the trace already exists, returns the existing header —
     * the caller's topic/recipe args are ignored on second call. This matches
     * how agents reconnect to an in-flight trace across sessions: you don't
     * want `--topic` on re-open to silently rewrite history.
     */
    public function openOrCreate(string $traceId, ?string $topic = null, ?string $recipe = null): TraceHeader
    {
        self::assertValidId($traceId);
        $path = $this->pathFor($traceId);
        if (is_file($path)) {
            return $this->readHeader($path, $traceId);
        }

        $this->ensureDir();
        $header = new TraceHeader(
            schemaVersion: TraceHeader::SCHEMA_VERSION,
            traceId:       $traceId,
            createdAt:     date('c'),
            topic:         $topic,
            recipe:        $recipe,
        );
        $line = json_encode($header->toArray(), JSON_UNESCAPED_SLASHES) . "\n";
        $ok = file_put_contents($path, $line, LOCK_EX);
        if ($ok === false) {
            throw new \RuntimeException("failed to write trace file: {$path}");
        }
        return $header;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function append(string $traceId, string $eventKind, string $summary, array $payload = []): TraceEvent
    {
        self::assertValidId($traceId);
        $path = $this->pathFor($traceId);
        if (!is_file($path)) {
            throw new \RuntimeException("trace '{$traceId}' does not exist — call start first");
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException("cannot open trace for append: {$path}");
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException("failed to lock trace for append: {$path}");
            }

            $nextId = $this->readLastEventIdFromHandle($handle) + 1;
            $event = new TraceEvent(
                eventId:   $nextId,
                at:        date('c'),
                eventKind: $eventKind,
                summary:   $summary,
                payload:   $payload,
            );
            $line = json_encode($event->toArray(), JSON_UNESCAPED_SLASHES) . "\n";

            if (fseek($handle, 0, SEEK_END) !== 0 || fwrite($handle, $line) === false || !fflush($handle)) {
                throw new \RuntimeException("failed to append to trace: {$path}");
            }

            flock($handle, LOCK_UN);

            return $event;
        } finally {
            fclose($handle);
        }
    }

    public function read(string $traceId): Trace
    {
        self::assertValidId($traceId);
        $path = $this->pathFor($traceId);
        if (!is_file($path)) {
            throw new \RuntimeException("trace '{$traceId}' does not exist");
        }

        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cannot open trace: {$path}");
        }

        $header = null;
        $events = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    continue;
                }
                /** @var array<string, mixed> $decoded */
                $kind = $decoded['kind'] ?? null;
                if ($kind === TraceHeader::KIND && $header === null) {
                    $header = TraceHeader::fromArray($decoded);
                } elseif ($kind === TraceEvent::KIND) {
                    $events[] = TraceEvent::fromArray($decoded);
                }
            }
        } finally {
            fclose($handle);
        }

        if ($header === null) {
            throw new \RuntimeException("trace '{$traceId}' missing header line");
        }
        return new Trace($header, $events);
    }

    /**
     * @return list<TraceHeader>
     */
    public function list(): array
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, '.ndjson')) {
                continue;
            }
            $traceId = substr($entry, 0, -strlen('.ndjson'));
            if (!preg_match(self::ID_PATTERN, $traceId)) {
                continue;
            }
            try {
                $out[] = $this->readHeader($dir . '/' . $entry, $traceId);
            } catch (\RuntimeException) {
                // Skip malformed traces rather than crashing the list.
                continue;
            }
        }
        usort($out, static fn(TraceHeader $a, TraceHeader $b) => strcmp($a->createdAt, $b->createdAt));
        return $out;
    }

    public function pathFor(string $traceId): string
    {
        return $this->dir() . '/' . $traceId . '.ndjson';
    }

    private function dir(): string
    {
        return ProjectRoot::get() . '/' . self::SUBDIR;
    }

    private function ensureDir(): void
    {
        $dir = $this->dir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("failed to create trace dir: {$dir}");
        }
    }

    private function readHeader(string $path, string $traceId): TraceHeader
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("cannot open trace: {$path}");
        }
        try {
            $line = fgets($handle);
        } finally {
            fclose($handle);
        }
        if ($line === false) {
            throw new \RuntimeException("empty trace file: {$path}");
        }
        $decoded = json_decode(trim($line), true);
        if (!is_array($decoded) || ($decoded['kind'] ?? null) !== TraceHeader::KIND) {
            throw new \RuntimeException("malformed header in {$path}");
        }
        /** @var array<string, mixed> $decoded */
        $header = TraceHeader::fromArray($decoded);
        // Normalise: if a malformed file has a mismatched id, trust the filename.
        if ($header->traceId !== $traceId) {
            $header = new TraceHeader(
                schemaVersion: $header->schemaVersion,
                traceId:       $traceId,
                createdAt:     $header->createdAt,
                topic:         $header->topic,
                recipe:        $header->recipe,
            );
        }
        return $header;
    }

    /**
     * Reads the greatest event id from an already-open trace handle.
     *
     * The caller is responsible for any locking discipline around this read.
     *
     * @param resource $handle
     */
    private function readLastEventIdFromHandle($handle): int
    {
        rewind($handle);

        $lastId = 0;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (!is_array($decoded)) {
                continue;
            }
            /** @var array<string, mixed> $decoded */
            if (($decoded['kind'] ?? null) === TraceEvent::KIND) {
                $rawId = $decoded['event_id'] ?? 0;
                $id = is_int($rawId) ? $rawId : (is_numeric($rawId) ? (int) $rawId : 0);
                if ($id > $lastId) {
                    $lastId = $id;
                }
            }
        }

        return $lastId;
    }
}
