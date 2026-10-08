<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Server\PageTimelineSinkInterface;
use Semitexa\Core\Server\PageTimeline;

/**
 * The Observatory's page timeline: one NDJSON file per page (its KISS session
 * id) under the journal directory, appended a line at a time — a page's HUG
 * requests and its stream may run on different workers, and an append of one
 * short line is atomic. Development only (ObservatoryMode::full()); a file
 * stops growing at MAX_BYTES, and files a day old are removed when listed.
 */
#[SatisfiesServiceContract(of: PageTimelineSinkInterface::class)]
final class ObservatoryPageTimeline implements PageTimelineSinkInterface
{
    public const MAX_BYTES = 1_048_576;

    public const KEEP_SECONDS = 86400;

    /**
     * Whether this sink lives in a console process (a scheduler, a queue
     * worker) rather than a Swoole worker. It decides what a signal sent with
     * no process open was: a worker's timer, or the console process's own
     * bookkeeping — the scheduler planning its runs is not a timer.
     */
    private bool $console = false;

    public static function forConsole(): self
    {
        $sink = new self();
        $sink->console = true;

        return $sink;
    }

    public static function dir(): string
    {
        return ObservatoryJournal::dir() . '/timeline';
    }

    public static function pathFor(string $sessionId): ?string
    {
        return preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $sessionId) === 1 ? self::dir() . '/' . $sessionId . '.ndjson' : null;
    }

    public function record(string $sessionId, array $entry): void
    {
        if (!ObservatoryMode::full()) {
            return;
        }
        $this->journal($sessionId, $entry);
        $path = self::pathFor($sessionId);
        if ($path === null) {
            return;
        }
        if (!is_dir(self::dir())) {
            @mkdir(self::dir(), 0775, true);
        }
        clearstatcache(true, $path);
        if (is_file($path) && (int) @filesize($path) >= self::MAX_BYTES) {
            return;
        }
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($line)) {
            @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    /**
     * The two moments of a live update the panel draws, written to the pulse
     * file it follows next to the journal: the server signalling a scope, and a page receiving
     * what that signal re-ran. Without them a page changes every few seconds
     * and the panel shows nothing happening.
     *
     * A page is named by a short hash: its KISS session id is what lets a page
     * subscribe, and the journal is read by more than the page's owner.
     *
     * @param array<string, mixed> $entry
     */
    private function journal(string $sessionId, array $entry): void
    {
        $type = $entry['type'] ?? null;
        if ($sessionId === PageTimeline::SERVER && $type === 'signal') {
            // The sink runs inside the publish, on the publisher's coroutine:
            // whatever process is open here is the one that sent the signal.
            // None open means background work no process wraps: in a worker
            // that is a timer, in a console process its own bookkeeping.
            $process = ObservatoryContext::current();
            ObservatoryJournal::pulse(array_filter([
                'ts' => date('c'),
                'event' => ObservatoryJournal::EVENT_SIGNAL,
                'scope' => $entry['scope'] ?? null,
                'tenant' => $entry['tenant'] ?? null,
                'origin' => $entry['origin'] ?? null,
                'via' => $process['kind'] ?? ($this->console ? 'console' : 'timer'),
                'process' => $process['id'] ?? null,
                'worker' => getmypid(),
            ], static fn (mixed $v): bool => $v !== null));

            return;
        }
        if ($type === 'rerun' && ($entry['sent'] ?? 'nothing') !== 'nothing') {
            ObservatoryJournal::pulse([
                'ts' => date('c'),
                'event' => ObservatoryJournal::EVENT_PUSH,
                'page' => substr(hash('sha256', $sessionId), 0, 8),
                'sent' => (string) $entry['sent'],
                'worker' => getmypid(),
            ]);
        }
    }
}
