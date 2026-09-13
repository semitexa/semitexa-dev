<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Environment;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The log lines that belong to one block of the picture.
 *
 * ## Bounded at both ends, and that is the whole design
 *
 * MEASURED on this dev host: `app.log` reached 380 MB. Rotation is size-based
 * and only runs when something writes, so a large file is a state this reader
 * will meet, not a hypothetical. Two naive readers both fail on it — one that
 * loads the file, and one that streams the whole thing looking for matches.
 *
 * So it reads a fixed window off the END of the file and nothing else: seek to
 * `size - WINDOW`, read forward, drop the first (probably partial) line. The
 * cost is the same on a 4 KB file and a 400 MB one. What that buys in
 * predictability it pays for in reach, which is stated rather than hidden:
 * only the recent past is visible, and a block that was busy an hour ago looks
 * quiet here. The panel is a live instrument; `ai:ask logs` is the tool for
 * the archive, and it already exists.
 *
 * Rotated siblings are deliberately not followed. Walking them would make the
 * worst case unbounded again, to answer a question this surface is not for.
 *
 * ## Why matching is exact
 *
 * `block` is written by {@see LogOriginAttribution} from the tracer's own open
 * span, so it is a span name and not free text. Matching it exactly means a
 * line appears under the block it was actually written in, or under none —
 * there is no substring rule that could quietly widen into the wrong block.
 */
#[AsService]
final class ObservatoryLogReader
{
    /**
     * How much of the tail to read. Large enough to cover a busy minute,
     * small enough that the answer costs the same whatever the file weighs.
     */
    private const WINDOW_BYTES = 512_000;

    /** Hard ceiling on lines returned, so one chatty block cannot flood the panel. */
    private const MAX_LINES = 40;

    private const DEFAULT_LOG_FILE = 'var/log/app.log';

    /**
     * Lines whose origin is `$block`, oldest first, at most MAX_LINES.
     *
     * @return list<array{ts: string, level: string, message: string, process: string|null, context: array<string, mixed>}>
     */
    public function forBlock(string $block, int $limit = self::MAX_LINES): array
    {
        $block = trim($block);
        if ($block === '') {
            return [];
        }

        $out = [];
        foreach ($this->tailLines() as $entry) {
            if (($entry['block'] ?? null) !== $block) {
                continue;
            }
            $context = $entry['context'] ?? [];
            $out[] = [
                'ts' => (string) ($entry['timestamp'] ?? ''),
                'level' => (string) ($entry['level'] ?? 'info'),
                'message' => (string) ($entry['message'] ?? ''),
                'process' => isset($entry['process']) ? (string) $entry['process'] : null,
                'context' => is_array($context) ? $context : [],
            ];
        }

        $limit = max(1, min($limit, self::MAX_LINES));

        return array_slice($out, -$limit);
    }

    /**
     * The decoded entries in the tail window.
     *
     * @return list<array<string, mixed>>
     */
    private function tailLines(): array
    {
        $path = $this->path();

        // A worker lives for days and PHP caches what it learned about this
        // file the first time. Another worker appends to app.log every second
        // and rotates it nightly, so a stale size makes the window start at an
        // offset that is no longer near the end — scanning further and further
        // back as the file grows, and past EOF once it rotates, which returns
        // nothing at all. Raised in review of dev#83.
        clearstatcache(true, $path);

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $from = max(0, $size - self::WINDOW_BYTES);
            if ($from > 0) {
                fseek($handle, $from);
                // The window almost certainly opened mid-line; that fragment is
                // not a log entry and must not be decoded as one.
                fgets($handle);
            }

            $entries = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '' || $line[0] !== '{') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $entries[] = $decoded;
                }
            }

            return $entries;
        } finally {
            fclose($handle);
        }
    }

    private function path(): string
    {
        $configured = Environment::getEnvValue('LOG_FILE');
        $file = $configured !== null && $configured !== '' ? $configured : self::DEFAULT_LOG_FILE;

        // Resolved the same way AsyncJsonLogger resolves it, including the part
        // that surprises: the logger builds projectRoot . '/' . ltrim($file),
        // so an absolute LOG_FILE lands UNDER the project root rather than at
        // the path it names. A reader that resolved it the sane way would look
        // somewhere the writer never wrote.
        return ProjectRoot::get() . '/' . ltrim($file, '/');
    }
}
