<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\TraceReader;

final class TraceReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-tracereader-' . uniqid();
        mkdir($this->dir, 0755, true);
        putenv('SEMITEXA_TRACE_DIR=' . $this->dir);
        putenv('APP_ENV=dev');
    }

    protected function tearDown(): void
    {
        putenv('SEMITEXA_TRACE_DIR');
        putenv('APP_ENV');
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    #[Test]
    public function the_viewer_is_off_outside_dev(): void
    {
        putenv('APP_ENV=prod');

        self::assertFalse((new TraceReader())->isEnabled());
    }

    #[Test]
    public function begin_and_end_events_are_paired_into_spans(): void
    {
        $this->write('a.json', [
            ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'context' => ['path' => '/x', 'method' => 'GET']],
            ['type' => 'begin', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 1.0, 'context' => []],
            ['type' => 'end', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 3.0, 'durationMs' => 2.0, 'context' => ['handler' => 'H']],
            ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 4.0, 'durationMs' => 4.0, 'context' => []],
        ]);

        $trace = (new TraceReader())->read('a.json');

        self::assertNotNull($trace);
        self::assertCount(2, $trace['spans']);
        $pipeline = $trace['spans'][1];
        self::assertSame('pipeline', $pipeline['name']);
        self::assertSame(1.0, $pipeline['startMs'], 'start comes from the begin event');
        self::assertSame(2.0, $pipeline['durationMs']);
        self::assertSame('H', $pipeline['context']['handler'], 'context from both ends is merged');
        self::assertSame(4.0, $trace['meta']['totalMs']);
    }

    #[Test]
    public function a_span_that_never_closed_survives_with_a_null_duration(): void
    {
        // The request died inside it. Dropping it would hide the one span most
        // worth seeing, which is the reason the stored format is flat.
        $this->write('b.json', [
            ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'context' => ['path' => '/boom']],
            ['type' => 'begin', 'name' => 'pipeline', 'depth' => 1, 'atMs' => 1.0, 'context' => []],
        ]);

        $trace = (new TraceReader())->read('b.json');

        self::assertNotNull($trace);
        $names = array_column($trace['spans'], 'name');
        self::assertContains('pipeline', $names);
        $pipeline = $trace['spans'][array_search('pipeline', $names, true)];
        self::assertNull($pipeline['durationMs']);
    }

    #[Test]
    public function queries_and_marks_are_separated_from_spans(): void
    {
        $this->write('c.json', [
            ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'context' => ['path' => '/x']],
            ['type' => 'mark', 'name' => 'auth.absent', 'depth' => 0, 'atMs' => 0.5, 'context' => []],
            ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 9.0, 'durationMs' => 9.0, 'context' => []],
            ['type' => 'query', 'name' => 'orm.query', 'depth' => 1, 'atMs' => null, 'durationMs' => 1.5, 'context' => ['sql' => 'SELECT 1', 'params' => 0]],
        ]);

        $trace = (new TraceReader())->read('c.json');

        self::assertNotNull($trace);
        self::assertCount(1, $trace['spans']);
        self::assertCount(1, $trace['marks']);
        self::assertCount(1, $trace['queries']);
        self::assertSame('SELECT 1', $trace['queries'][0]['sql']);
    }

    #[Test]
    public function a_file_name_cannot_escape_the_trace_directory(): void
    {
        // The name arrives from a query string and this reads from disk.
        self::assertNull((new TraceReader())->read('../../../etc/passwd'));
    }

    #[Test]
    public function the_list_summarises_newest_first(): void
    {
        $this->write('20260101-000000-aaa.json', [
            ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'context' => ['path' => '/old', 'method' => 'GET']],
            ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 1.0, 'durationMs' => 1.0, 'context' => []],
        ]);
        $this->write('20260202-000000-bbb.json', [
            ['type' => 'begin', 'name' => 'request', 'depth' => 0, 'atMs' => 0.0, 'context' => ['path' => '/new', 'method' => 'POST']],
            ['type' => 'end', 'name' => 'request', 'depth' => 0, 'atMs' => 5.0, 'durationMs' => 5.0, 'context' => []],
            ['type' => 'mark', 'name' => 'orm.summary', 'depth' => 0, 'atMs' => 5.0, 'context' => ['queries' => 3]],
        ]);

        $list = (new TraceReader())->list();

        self::assertSame('/new', $list[0]['path']);
        self::assertSame(3, $list[0]['queries']);
        self::assertSame('/old', $list[1]['path']);
    }

    #[Test]
    public function a_corrupt_file_is_skipped_rather_than_fatal(): void
    {
        file_put_contents($this->dir . '/broken.json', '{not json');

        self::assertNull((new TraceReader())->read('broken.json'));
        self::assertSame([], (new TraceReader())->list());
    }

    /** @param list<array<string, mixed>> $events */
    /**
     * A capped trace loses its `end:request`, which is where the total normally
     * comes from. The recorder writes elapsed time outside the capped list for
     * that case, and both views have to fall back to it — otherwise the trace
     * reads as 0 ms and every bar is scaled against nothing.
     */
    #[Test]
    public function a_capped_trace_reports_elapsed_time_instead_of_zero(): void
    {
        $this->writeRaw('capped.json', [
            'recordedAt' => '2026-08-04T05:00:00+00:00',
            'truncated' => true,
            'totalMs' => 812.5,
            'events' => [
                ['type' => 'begin', 'name' => 'sse', 'depth' => 0, 'atMs' => 0.0, 'cid' => 2, 'pcid' => 0, 'context' => ['sse' => true, 'path' => '/__semitexa_kiss']],
                ['type' => 'mark', 'name' => 'tick', 'depth' => 1, 'atMs' => 5.0, 'cid' => 2, 'pcid' => 0, 'context' => []],
            ],
        ]);

        $trace = (new TraceReader())->read('capped.json');

        self::assertNotNull($trace);
        self::assertSame(812.5, $trace['meta']['totalMs']);
        self::assertTrue($trace['meta']['truncated']);

        $listed = (new TraceReader())->list();
        self::assertSame(812.5, $listed[0]['totalMs']);
    }

    /**
     * Two coroutines inside one trace can open a span of the same name. Paired by
     * name alone, the first begin matches the second end.
     */
    #[Test]
    public function spans_are_paired_within_their_own_coroutine(): void
    {
        $this->write('coro.json', [
            ['type' => 'begin', 'name' => 'sse', 'depth' => 0, 'atMs' => 0.0, 'cid' => 1, 'pcid' => 0, 'context' => []],
            ['type' => 'begin', 'name' => 'block', 'depth' => 1, 'atMs' => 1.0, 'cid' => 2, 'pcid' => 1, 'context' => ['which' => 'a']],
            ['type' => 'begin', 'name' => 'block', 'depth' => 1, 'atMs' => 2.0, 'cid' => 3, 'pcid' => 1, 'context' => ['which' => 'b']],
            ['type' => 'end', 'name' => 'block', 'depth' => 1, 'atMs' => 8.0, 'cid' => 2, 'pcid' => 1, 'durationMs' => 7.0, 'context' => []],
            ['type' => 'end', 'name' => 'block', 'depth' => 1, 'atMs' => 9.0, 'cid' => 3, 'pcid' => 1, 'durationMs' => 7.0, 'context' => []],
            ['type' => 'end', 'name' => 'sse', 'depth' => 0, 'atMs' => 10.0, 'cid' => 1, 'pcid' => 0, 'durationMs' => 10.0, 'context' => []],
        ]);

        $trace = (new TraceReader())->read('coro.json');
        self::assertNotNull($trace);

        $blocks = array_values(array_filter(
            $trace['spans'],
            static fn (array $s): bool => $s['name'] === 'block',
        ));

        self::assertCount(2, $blocks);
        // Each end keeps the start time from its OWN coroutine's begin.
        $byCid = [];
        foreach ($blocks as $b) {
            $byCid[$b['cid']] = $b;
        }
        self::assertSame(1.0, $byCid[2]['startMs']);
        self::assertSame(2.0, $byCid[3]['startMs']);
        self::assertSame('a', $byCid[2]['context']['which']);
        self::assertSame('b', $byCid[3]['context']['which']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function writeRaw(string $name, array $payload): void
    {
        file_put_contents($this->dir . '/' . $name, json_encode($payload));
    }

    private function write(string $name, array $events): void
    {
        file_put_contents(
            $this->dir . '/' . $name,
            json_encode(['recordedAt' => '2026-08-04T05:00:00+00:00', 'events' => $events]),
        );
    }
}
