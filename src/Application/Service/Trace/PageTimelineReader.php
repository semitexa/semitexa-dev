<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * Reads the Observatory's page timelines (ObservatoryPageTimeline): the pages
 * seen lately, and one page's events in order — what its components sent,
 * what its stream wrote back, its subscriptions and re-runs.
 */
#[AsService]
final class PageTimelineReader
{
    /**
     * The pages with a timeline, most recently active first. Timelines a day
     * old are removed on the way.
     *
     * @return list<array{session: string, updatedAt: string, bytes: int}>
     */
    public function pages(int $limit = 20): array
    {
        $dir = ObservatoryPageTimeline::dir();
        $pages = [];
        foreach (glob($dir . '/*.ndjson') ?: [] as $path) {
            $mtime = (int) @filemtime($path);
            if ($mtime < time() - ObservatoryPageTimeline::KEEP_SECONDS) {
                @unlink($path);
                continue;
            }
            $pages[] = ['session' => basename($path, '.ndjson'), 'mtime' => $mtime, 'bytes' => (int) @filesize($path)];
        }
        usort($pages, static fn (array $a, array $b): int => $b['mtime'] <=> $a['mtime']);

        return array_map(
            static fn (array $p): array => ['session' => $p['session'], 'updatedAt' => date('c', $p['mtime']), 'bytes' => $p['bytes']],
            array_slice($pages, 0, max(1, $limit)),
        );
    }

    /**
     * One page's last $limit events, oldest first; [] when it has none.
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $session, int $limit = 500): array
    {
        $path = ObservatoryPageTimeline::pathFor($session);
        if ($path === null || !is_file($path)) {
            return [];
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        $events = [];
        try {
            while (($line = fgets($handle)) !== false) {
                $row = json_decode($line, true);
                if (is_array($row)) {
                    $events[] = $row;
                    if (count($events) > $limit) {
                        array_shift($events);
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        return $events;
    }
}
