<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;
use Semitexa\Testing\TestCase;

/**
 * The reader folds journal lines into the panel's picture: a begin with no end
 * is LIVE, an old one is flagged stale rather than hidden, and the recent list
 * works even when a begin fell off the tail window — the end line carries
 * everything it needs.
 */
final class ObservatoryReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/semitexa-observatory-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function journal(array $rows): void
    {
        $lines = array_map(static fn (array $r): string => json_encode($r), $rows);
        file_put_contents($this->dir . '/journal-' . date('Ymd') . '.ndjson', implode("\n", $lines) . "\n");
    }

    #[Test]
    public function a_begin_without_an_end_is_live_and_a_completed_pair_is_recent(): void
    {
        $now = date('c');
        $this->journal([
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-1-aa', 'kind' => 'http', 'name' => 'SlowPayload', 'worker' => 1],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-1-bb', 'kind' => 'http', 'name' => 'FastPayload', 'worker' => 1],
            ['ts' => $now, 'event' => 'end', 'id' => 'p-1-bb', 'kind' => 'http', 'name' => 'FastPayload', 'worker' => 1, 'durationMs' => 12.5, 'trace' => 't.json'],
        ]);

        $snap = (new ObservatoryReader())->snapshot();

        self::assertSame(1, $snap['counts']['live']);
        self::assertSame('SlowPayload', $snap['live'][0]['name']);
        self::assertFalse($snap['live'][0]['stale'], 'a fresh in-flight process is live, not stale');

        self::assertCount(1, $snap['recent']);
        self::assertSame('FastPayload', $snap['recent'][0]['name']);
        self::assertSame(12.5, $snap['recent'][0]['durationMs']);
        self::assertSame('t.json', $snap['recent'][0]['trace'], 'the waterfall link survives the fold');
    }

    #[Test]
    public function an_ancient_begin_is_flagged_stale_not_hidden(): void
    {
        // A worker killed mid-request never writes its end line. Hiding it
        // would make the panel lie; the flag lets the human judge.
        $this->journal([
            ['ts' => date('c', time() - 7200), 'event' => 'begin', 'id' => 'p-1-cc', 'kind' => 'http', 'name' => 'DeadPayload', 'worker' => 2],
        ]);

        $snap = (new ObservatoryReader())->snapshot();

        self::assertSame(1, $snap['counts']['live']);
        self::assertTrue($snap['live'][0]['stale']);
        self::assertSame(1, $snap['counts']['stale']);
    }

    #[Test]
    public function an_end_whose_begin_fell_off_the_window_still_lands_in_recent(): void
    {
        $this->journal([
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-9-zz', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 9, 'durationMs' => 20000.0],
        ]);

        $snap = (new ObservatoryReader())->snapshot();

        self::assertSame(0, $snap['counts']['live']);
        self::assertCount(1, $snap['recent']);
        self::assertSame('ssr.kiss', $snap['recent'][0]['name']);
    }

    #[Test]
    public function newest_finished_first_and_longest_running_live_first(): void
    {
        $this->journal([
            ['ts' => date('c', time() - 300), 'event' => 'begin', 'id' => 'p-1-old', 'kind' => 'sse', 'name' => 'old-live', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-new', 'kind' => 'http', 'name' => 'new-live', 'worker' => 1],
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-1-f1', 'kind' => 'http', 'name' => 'first-done', 'worker' => 1, 'durationMs' => 1.0],
            ['ts' => date('c'), 'event' => 'end', 'id' => 'p-1-f2', 'kind' => 'http', 'name' => 'last-done', 'worker' => 1, 'durationMs' => 1.0],
        ]);

        $snap = (new ObservatoryReader())->snapshot();

        self::assertSame('old-live', $snap['live'][0]['name'], 'the stuck-request hunt reads top-down');
        self::assertSame('last-done', $snap['recent'][0]['name'], 'a developer looks for what JUST happened');
    }

    #[Test]
    public function corrupt_lines_are_skipped_not_fatal(): void
    {
        file_put_contents(
            $this->dir . '/journal-' . date('Ymd') . '.ndjson',
            "{ not json\n" . json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-ok', 'kind' => 'http', 'name' => 'ok', 'worker' => 1]) . "\n",
        );

        $snap = (new ObservatoryReader())->snapshot();

        self::assertSame(1, $snap['counts']['live'], 'a half-written line must not take the panel down');
    }

    #[Test]
    public function stream_bootstraps_then_follows_only_new_lines(): void
    {
        $path = $this->dir . '/journal-' . date('Ymd') . '.ndjson';
        file_put_contents($path, implode("\n", [
            json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-a', 'kind' => 'http', 'name' => '/a', 'worker' => 1]),
            json_encode(['ts' => date('c'), 'event' => 'end', 'id' => 'p-1-a', 'kind' => 'http', 'name' => '/a', 'worker' => 1, 'durationMs' => 1.5]),
        ]) . "\n");

        $reader = new ObservatoryReader();
        $first = $reader->stream(null);
        self::assertTrue($first['reset']);
        self::assertCount(2, $first['rows']);
        self::assertSame(date('Ymd') . ':' . filesize($path), $first['cursor']);

        $quiet = $reader->stream($first['cursor']);
        self::assertFalse($quiet['reset']);
        self::assertSame([], $quiet['rows'], 'nothing appended, nothing returned');
        self::assertSame($first['cursor'], $quiet['cursor']);

        file_put_contents($path, json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-b', 'kind' => 'sse', 'name' => '/kiss', 'worker' => 1]) . "\n", FILE_APPEND);
        $next = $reader->stream($quiet['cursor']);
        self::assertSame(['p-1-b'], array_column($next['rows'], 'id'));
        self::assertSame(date('Ymd') . ':' . filesize($path), $next['cursor']);
    }

    #[Test]
    public function stream_leaves_a_half_written_line_for_the_next_poll(): void
    {
        $path = $this->dir . '/journal-' . date('Ymd') . '.ndjson';
        $whole = json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-2-a', 'kind' => 'http', 'name' => '/a', 'worker' => 2]) . "\n";
        file_put_contents($path, $whole);
        $reader = new ObservatoryReader();
        $cursor = $reader->stream(null)['cursor'];

        // A writer mid-line: the bytes are there, the newline is not yet.
        $partial = json_encode(['ts' => date('c'), 'event' => 'end', 'id' => 'p-2-a', 'kind' => 'http', 'name' => '/a']);
        file_put_contents($path, substr($partial, 0, 20), FILE_APPEND);
        $poll = $reader->stream($cursor);
        self::assertSame([], $poll['rows']);
        self::assertSame($cursor, $poll['cursor'], 'the cursor must not advance past a line that is not finished');

        file_put_contents($path, substr($partial, 20) . "\n", FILE_APPEND);
        $poll = $reader->stream($poll['cursor']);
        self::assertSame(['end'], array_column($poll['rows'], 'event'));
    }

    #[Test]
    public function stream_resets_on_a_garbage_or_shrunken_cursor(): void
    {
        $path = $this->dir . '/journal-' . date('Ymd') . '.ndjson';
        file_put_contents($path, json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-3-a', 'kind' => 'http', 'name' => '/a', 'worker' => 3]) . "\n");
        $reader = new ObservatoryReader();

        self::assertTrue($reader->stream('not-a-cursor')['reset']);
        self::assertTrue($reader->stream('20200101:0')['reset'], 'a cursor from another day than yesterday is stale');
        self::assertTrue($reader->stream(date('Ymd') . ':999999')['reset'], 'a file shorter than the cursor was rotated or replaced');
    }
}
