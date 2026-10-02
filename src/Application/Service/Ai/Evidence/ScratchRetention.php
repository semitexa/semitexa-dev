<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * `var/tmp/` — every agent's and every tool's scratch space — had no end of
 * life: 1.7 GB in 47 000 files on 2026-10-02, the oldest from April, among
 * them isolated graph databases and exported trees of the whole workspace.
 *
 * An entry is stale when nothing inside it changed for {@see DAYS} days: the
 * NEWEST mtime of a directory decides, so a harness edited today is kept even
 * if its fixtures are months old. Removed only by `ai:evidence prune --tmp`
 * — never on its own: scratch can be somebody's unfinished work, and the
 * operator sees the list first (--dry-run).
 */
final class ScratchRetention
{
    public const DIR = 'var/tmp';
    public const DAYS = 14;

    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * @return list<array{path: string, bytes: int, newest: string}> stale top-level entries, oldest first
     */
    public function stale(\DateTimeImmutable $now): array
    {
        $dir = rtrim($this->projectRoot, '/') . '/' . self::DIR;
        $cutoff = $now->getTimestamp() - self::DAYS * 86400;
        $stale = [];
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            // Dot entries are locks and markers (.project-graph.lock), not scratch.
            if ($name === '' || $name[0] === '.') {
                continue;
            }
            [$newest, $bytes] = self::measure($dir . '/' . $name);
            if ($newest < $cutoff) {
                $stale[] = ['path' => self::DIR . '/' . $name, 'bytes' => $bytes, 'newest' => gmdate('Y-m-d', $newest)];
            }
        }
        usort($stale, static fn (array $a, array $b): int => [$a['newest'], $a['path']] <=> [$b['newest'], $b['path']]);

        return $stale;
    }

    /**
     * @param list<array{path: string, bytes: int, newest: string}> $entries from {@see stale()}
     * @return list<array{path: string, bytes: int, newest: string}> the entries actually gone: one a
     *         root-owned file kept is not reported as removed
     */
    public function remove(array $entries): array
    {
        $root = rtrim($this->projectRoot, '/');
        $gone = [];
        foreach ($entries as $entry) {
            if (!str_starts_with($entry['path'], self::DIR . '/') || str_contains($entry['path'], '..')) {
                continue;
            }
            $path = $root . '/' . $entry['path'];
            self::delete($path);
            clearstatcache();
            if (!file_exists($path) && !is_link($path)) {
                $gone[] = $entry;
            }
        }

        return $gone;
    }

    /** @return array{int, int} newest mtime, bytes */
    private static function measure(string $path): array
    {
        if (is_link($path) || !is_dir($path)) {
            $stat = @lstat($path);

            return [is_array($stat) ? (int) $stat['mtime'] : 0, is_array($stat) ? (int) $stat['size'] : 0];
        }
        $newest = (int) @filemtime($path);
        $bytes = 0;
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            [$m, $b] = self::measure($path . '/' . $name);
            $newest = max($newest, $m);
            $bytes += $b;
        }

        return [$newest, $bytes];
    }

    private static function delete(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                self::delete($path . '/' . $name);
            }
        }
        @rmdir($path);
    }
}
