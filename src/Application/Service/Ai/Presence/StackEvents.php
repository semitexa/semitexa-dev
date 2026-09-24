<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

/**
 * Who started, stopped or restarted the shared dev stack, and when.
 *
 * Written by bin/semitexa (server:start / server:stop / server:restart) before
 * it acts, so a restart that fails is still on record. A restart takes the
 * running server down and removes every one-off CLI container — the other
 * agents' work in flight — and before this log they could only guess who did
 * it. `by` is the agent session that ran it, or null when the caller never
 * joined.
 */
final class StackEvents
{
    public const FILE = 'var/ai-work/stack-events.ndjson';

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Newest first.
     *
     * @return list<array{at: string, action: string, by: ?string, user: string, detail: string, age_s: int}>
     */
    public function recent(int $limit = 5, ?int $now = null): array
    {
        $now ??= time();
        $path = $this->projectRoot . '/' . self::FILE;
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [];
        $out = [];
        foreach (array_reverse(array_slice($lines, -200)) as $line) {
            $e = json_decode($line, true);
            if (!is_array($e) || !is_string($e['at'] ?? null) || !is_string($e['action'] ?? null)) {
                continue;
            }
            $out[] = [
                'at' => $e['at'],
                'action' => $e['action'],
                'by' => is_string($e['by'] ?? null) ? $e['by'] : null,
                'user' => (string) ($e['user'] ?? ''),
                'detail' => (string) ($e['detail'] ?? ''),
                'age_s' => max(0, $now - (int) strtotime($e['at'])),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array{at: string, action: string, by: ?string, detail: string, age_s: int} $e
     */
    public static function describe(array $e): string
    {
        $ago = $e['age_s'] < 60 ? 'just now' : ($e['age_s'] < 3600 ? intdiv($e['age_s'], 60) . ' min ago' : substr($e['at'], 0, 16) . ' UTC');

        return sprintf(
            '%s%s by %s, %s',
            $e['action'],
            $e['detail'] !== '' ? ' (' . $e['detail'] . ')' : '',
            $e['by'] ?? 'an agent that never joined',
            $ago,
        );
    }
}
