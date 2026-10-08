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

    /**
     * `ai:observe ps` listed durations only, so a 500 and a 200 read the same
     * to an agent looking for what just went wrong.
     */
    #[Test]
    public function a_finished_process_says_what_it_ended_as(): void
    {
        $now = date('c');
        $this->journal([
            ['ts' => $now, 'event' => 'end', 'id' => 'p-1-ok', 'kind' => 'http', 'name' => 'Fine', 'worker' => 1, 'durationMs' => 1.0, 'context' => ['http_status' => 404]],
            ['ts' => $now, 'event' => 'end', 'id' => 'p-1-bad', 'kind' => 'http', 'name' => 'Broken', 'worker' => 1, 'durationMs' => 1.0, 'context' => ['http_status' => 500]],
            ['ts' => $now, 'event' => 'end', 'id' => 'p-1-esc', 'kind' => 'http', 'name' => 'Escaped', 'worker' => 1, 'durationMs' => 1.0, 'context' => ['exception' => 'LogicException']],
            // Traced: the mark says exception, but the answered 404 decides.
            ['ts' => $now, 'event' => 'end', 'id' => 'p-1-t404', 'kind' => 'http', 'name' => 'Traced404', 'worker' => 1, 'durationMs' => 1.0, 'phases' => ['outcome' => 'exception'], 'context' => ['http_status' => 404]],
            // Recorded before core reported a status: only the mark can say.
            ['ts' => $now, 'event' => 'end', 'id' => 'p-1-old', 'kind' => 'http', 'name' => 'OldTraced', 'worker' => 1, 'durationMs' => 1.0, 'phases' => ['outcome' => 'exception']],
        ]);

        $snap = (new ObservatoryReader())->snapshot();
        $byId = array_column($snap['recent'], null, 'id');

        self::assertSame(404, $byId['p-1-ok']['httpStatus']);
        self::assertArrayNotHasKey('failed', $byId['p-1-ok'], 'a 4xx is the server answering as designed');
        self::assertTrue($byId['p-1-bad']['failed']);
        self::assertSame('LogicException', $byId['p-1-esc']['exception']);
        self::assertArrayNotHasKey('failed', $byId['p-1-t404'], 'a traced 404 counts the same as an untraced one');
        self::assertTrue($byId['p-1-old']['failed']);
        self::assertSame(3, $snap['counts']['recentFailures']);
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

    /**
     * tk-dev-observe-tail-oom: `ai:observe tail` decoded the whole 5 MB window
     * of each journal at once and ran a busy day out of its 128 MB.
     */
    #[Test]
    public function tail_holds_only_the_rows_it_returns_and_find_reads_a_window_past_its_size(): void
    {
        $path = $this->dir . '/journal-' . date('Ymd') . '.ndjson';
        $handle = fopen($path, 'wb');
        $padding = str_repeat('x', 400);
        for ($i = 0; $i < 16_000; $i++) { // ~7 MB: past the 5 MB window
            fwrite($handle, json_encode(['ts' => date('c'), 'event' => 'end', 'id' => 'p-1-' . $i, 'kind' => $i % 2 === 0 ? 'http' : 'job', 'name' => 'n' . $i, 'worker' => 1, 'context' => ['pad' => $padding]]) . "\n");
        }
        fclose($handle);
        $reader = new ObservatoryReader();

        memory_reset_peak_usage();
        $before = memory_get_usage();
        $rows = $reader->tailRecords(3, kind: 'job');
        $grew = memory_get_peak_usage() - $before;

        self::assertSame(['n15995', 'n15997', 'n15999'], array_column($rows, 'name'), 'the last ones, chronological, filtered');
        self::assertLessThan(2_000_000, $grew, 'a line at a time, not the window decoded at once');
        self::assertSame([], $reader->tailRecords(0));

        self::assertSame('n15990', $reader->find('p-1-15990')['end']['name'] ?? null);
        self::assertNull($reader->find('p-1-10')['end'], 'older than the window: not read');
        self::assertCount(60, $reader->snapshot()['recent']);
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
        self::assertSame(date('Ymd') . ':' . filesize($path) . ':p0', $first['cursor'], 'no pulse file yet: pulses start at 0');

        $quiet = $reader->stream($first['cursor']);
        self::assertFalse($quiet['reset']);
        self::assertSame([], $quiet['rows'], 'nothing appended, nothing returned');
        self::assertSame($first['cursor'], $quiet['cursor']);

        file_put_contents($path, json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-b', 'kind' => 'sse', 'name' => '/kiss', 'worker' => 1]) . "\n", FILE_APPEND);
        $next = $reader->stream($quiet['cursor']);
        self::assertSame(['p-1-b'], array_column($next['rows'], 'id'));
        self::assertSame(date('Ymd') . ':' . filesize($path) . ':p0', $next['cursor']);
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

    /**
     * A restart kills every worker, and a killed worker writes no ends: the
     * panel used to show each session of every earlier run as live — seven
     * "open" KISS connections for one browser tab.
     */
    #[Test]
    public function a_server_restart_ends_what_the_previous_run_held_open(): void
    {
        $now = date('c');
        $this->journal([
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-7-old', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 7, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-7-legacy', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 7],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-7-test', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 7, 'host' => 'test'],
            ['ts' => $now, 'event' => 'server-start', 'host' => 'dev'],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-7-new', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 7, 'host' => 'dev'],
        ]);

        $live = array_column((new ObservatoryReader())->snapshot()['live'], 'id');
        sort($live);

        self::assertSame(['p-7-new', 'p-7-test'], $live, 'another host\'s server start says nothing about this one');
    }

    #[Test]
    public function a_worker_start_or_stop_ends_only_what_that_pid_held_open(): void
    {
        $now = date('c');
        $this->journal([
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-18-a', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 18, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-19-a', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 19, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-20-a', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 20, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'worker-start', 'worker' => 18, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'worker-stop', 'worker' => 20, 'host' => 'dev', 'crashed' => true],
        ]);

        self::assertSame(['p-19-a'], array_column((new ObservatoryReader())->snapshot()['live'], 'id'));
    }

    #[Test]
    public function find_names_the_marker_that_cut_a_process_and_prefers_a_real_end(): void
    {
        $now = date('c');
        $this->journal([
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-18-cut', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 18, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'begin', 'id' => 'p-19-drained', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 19, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'worker-stop', 'worker' => 19, 'host' => 'dev'],
            ['ts' => $now, 'event' => 'end', 'id' => 'p-19-drained', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 19, 'durationMs' => 5.0],
            ['ts' => $now, 'event' => 'server-start', 'host' => 'dev'],
        ]);
        $reader = new ObservatoryReader();

        self::assertSame('server-start', $reader->find('p-18-cut')['lost']['event'] ?? null);
        self::assertNull($reader->find('p-19-drained')['lost'], 'it ended, after the stop marker: done, not lost');
        self::assertNotNull($reader->find('p-19-drained')['end']);
    }

    #[Test]
    public function stream_carries_new_pulses_and_a_cursor_from_before_pulses_starts_at_their_end(): void
    {
        $journal = $this->dir . '/journal-' . date('Ymd') . '.ndjson';
        $pulses = $this->dir . '/pulses-' . date('Ymd') . '.ndjson';
        file_put_contents($journal, json_encode(['ts' => date('c'), 'event' => 'begin', 'id' => 'p-1-a', 'kind' => 'sse', 'name' => 'ssr.kiss', 'worker' => 1]) . "\n");
        file_put_contents($pulses, json_encode(['ts' => date('c'), 'event' => 'signal', 'scope' => 'old']) . "\n");
        $reader = new ObservatoryReader();

        $first = $reader->stream(null);
        self::assertSame(['begin'], array_column($first['rows'], 'event'), 'a bootstrap replays no pulses: they are news, not history');

        file_put_contents($pulses, json_encode(['ts' => date('c'), 'event' => 'signal', 'scope' => 'ui_playground_wallet']) . "\n"
            . json_encode(['ts' => date('c'), 'event' => 'push', 'page' => 'abcd1234']) . "\n", FILE_APPEND);
        $next = $reader->stream($first['cursor']);
        self::assertSame(['signal', 'push'], array_column($next['rows'], 'event'));
        self::assertSame('ui_playground_wallet', $next['rows'][0]['scope']);
        self::assertSame([], $reader->stream($next['cursor'])['rows'], 'each pulse is delivered once');

        $legacy = $reader->stream(date('Ymd') . ':' . filesize($journal));
        self::assertFalse($legacy['reset'], 'a cursor without its pulse part is still a cursor');
        self::assertSame([], $legacy['rows']);
        self::assertStringEndsWith(':p' . filesize($pulses), $legacy['cursor']);
    }

    #[Test]
    public function a_pulse_backlog_too_big_to_be_news_is_skipped_not_replayed(): void
    {
        $journal = $this->dir . '/journal-' . date('Ymd') . '.ndjson';
        $pulses = $this->dir . '/pulses-' . date('Ymd') . '.ndjson';
        file_put_contents($journal, '');
        file_put_contents($pulses, '');
        $reader = new ObservatoryReader();
        $cursor = $reader->stream(null)['cursor'];

        $line = json_encode(['ts' => date('c'), 'event' => 'push', 'page' => 'abcd1234']) . "\n";
        file_put_contents($pulses, str_repeat($line, (int) ceil(70000 / strlen($line))));
        $after = $reader->stream($cursor);

        self::assertSame([], $after['rows']);
        self::assertStringEndsWith(':p' . filesize($pulses), $after['cursor']);
    }
}

