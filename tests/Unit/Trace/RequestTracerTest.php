<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryContext;
use Semitexa\Dev\Application\Service\Trace\RequestTracer;
use Semitexa\Dev\Application\Service\Trace\TraceBuffer;
use Semitexa\Dev\Application\Service\Trace\TraceContext;
use Semitexa\Orm\Adapter\QueryRecorder;

/**
 * The tracer sits on the request path of every request in every environment, so
 * the properties that matter most are the negative ones: that it records nothing
 * unless asked, and that it cannot break the request it is observing.
 */
final class RequestTracerTest extends TestCase
{
    private string $root;
    private ?string $marker = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-tracer-' . uniqid();
        mkdir($this->root, 0755, true);
        TraceContext::resetFallback();
        ObservatoryContext::reset();
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_TRACE_DIR=' . $this->root . '/var/trace');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->root . '/var/observatory');
        $this->marker = null;
    }

    protected function tearDown(): void
    {
        TraceContext::resetFallback();
        ObservatoryContext::reset();
        putenv('APP_ENV');
        putenv('SEMITEXA_TRACE_DIR');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        $this->removeDir($this->root);
    }

    #[Test]
    public function an_ordinary_request_writes_nothing(): void
    {
        // The default case, and the one that must never regress: no marker means
        // no file, no matter how many spans the framework reports.
        $this->runRequest(new RequestTracer());

        self::assertSame([], $this->traceFiles(), 'an unmarked request must leave no trace file');
    }

    #[Test]
    public function a_marked_request_is_recorded(): void
    {
        $this->marker = '1';

        $this->runRequest(new RequestTracer());

        $files = $this->traceFiles();
        self::assertCount(1, $files);

        $trace = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($trace);
        $names = array_column($trace['events'], 'name');
        self::assertContains('request', $names);
        self::assertContains('payload.hydrate_and_validate', $names);
        self::assertContains('pipeline', $names);
    }

    #[Test]
    public function the_marker_is_taken_from_the_span_context(): void
    {
        $this->marker = '1';

        $this->runRequest(new RequestTracer());

        self::assertCount(1, $this->traceFiles());
    }

    #[Test]
    public function a_marked_request_outside_dev_is_not_recorded(): void
    {
        // Both conditions are required. Someone who installed dev dependencies in
        // production must not be able to dump traces by adding a query parameter.
        putenv('APP_ENV=prod');
        $this->marker = '1';

        $this->runRequest(new RequestTracer());

        self::assertSame([], $this->traceFiles());
    }

    #[Test]
    public function spans_carry_durations_and_nesting_depth(): void
    {
        $this->marker = '1';
        $tracer = new RequestTracer();

        $tracer->begin('request', ['method' => 'GET', 'path' => '/x', 'marker' => $this->marker]);
        $tracer->begin('pipeline');
        $tracer->end('pipeline', ['handler' => 'App\\Handler']);
        $tracer->end('request');

        $events = json_decode((string) file_get_contents($this->traceFiles()[0]), true)['events'];
        $byName = [];
        foreach ($events as $e) {
            $byName[$e['type'] . ':' . $e['name']] = $e;
        }

        self::assertSame(0, $byName['begin:request']['depth']);
        self::assertSame(1, $byName['begin:pipeline']['depth'], 'nested span sits one level deeper');
        self::assertIsFloat($byName['end:pipeline']['durationMs']);
        self::assertSame('App\\Handler', $byName['end:pipeline']['context']['handler']);
    }

    #[Test]
    public function objects_and_arrays_are_reduced_rather_than_embedded(): void
    {
        // A trace is written to disk and read later. Embedding a payload wholesale
        // would put request data in a file nobody remembers creating.
        $this->marker = '1';
        $tracer = new RequestTracer();

        $tracer->begin('request', ['method' => 'GET', 'path' => '/x', 'marker' => $this->marker]);
        $tracer->mark('thing', [
            'obj' => new \stdClass(),
            'arr' => [1, 2, 3],
            'long' => str_repeat('a', 500),
        ]);
        $tracer->end('request');

        $events = json_decode((string) file_get_contents($this->traceFiles()[0]), true)['events'];
        $mark = array_values(array_filter($events, static fn (array $e): bool => $e['name'] === 'thing'))[0];

        self::assertSame('stdClass', $mark['context']['obj']);
        self::assertSame('[array:3]', $mark['context']['arr']);
        self::assertSame(200, mb_strlen($mark['context']['long']), 'long strings are truncated');
    }

    #[Test]
    public function an_unbalanced_end_does_not_throw(): void
    {
        // The interface forbids throwing. Closing a span that was never opened is
        // the likeliest way a future call site gets it wrong.
        $this->marker = '1';
        $tracer = new RequestTracer();

        $tracer->begin('request', ['method' => 'GET', 'path' => '/x', 'marker' => $this->marker]);
        $tracer->end('never-opened');
        $tracer->end('request');

        self::assertCount(1, $this->traceFiles());
    }

    #[Test]
    public function every_event_records_the_coroutine_it_came_from(): void
    {
        // Outside a coroutine both are 0; what matters is that the fields exist,
        // because the flow diagram distinguishes concurrent from sequential work
        // by them rather than by timings that happen to overlap.
        $this->marker = '1';
        $this->runRequest(new RequestTracer());

        $events = json_decode((string) file_get_contents($this->traceFiles()[0]), true)['events'];
        foreach ($events as $e) {
            if ($e['type'] === 'query') {
                continue;
            }
            self::assertArrayHasKey('cid', $e, $e['name'] . ' carries no cid');
            self::assertArrayHasKey('pcid', $e, $e['name'] . ' carries no pcid');
        }
    }

    /**
     * Outside a coroutine the buffer lives in one process-local slot, so this
     * cannot model two requests running at once - {@see RequestTracerCoroutineIsolationTest}
     * does that with real coroutines. What it does pin is the property that made
     * the old version of this test pass for the wrong reason: a second span
     * carrying the root name is nested work, and its end must not flush the trace
     * around it. Before that was counted, the untraced `end('request')` below
     * closed the traced buffer early, and the assertion still passed because
     * `begin:pipeline` had already been recorded.
     */
    #[Test]
    public function a_nested_root_span_does_not_close_the_trace_around_it(): void
    {
        $tracer = new RequestTracer();
        $this->marker = '1';
        $tracer->begin('request', ['method' => 'GET', 'path' => '/traced', 'marker' => '1']);
        $tracer->begin('pipeline');

        $inner = new RequestTracer();
        $inner->begin('request', ['method' => 'GET', 'path' => '/other', 'marker' => null]);
        $inner->end('request');

        $tracer->end('pipeline', ['handler' => 'H']);
        $tracer->end('request');

        $files = $this->traceFiles();
        self::assertCount(1, $files, 'only one trace is written');

        $events = json_decode((string) file_get_contents($files[0]), true)['events'];
        $ends = [];
        foreach ($events as $i => $e) {
            if ($e['type'] === 'end') {
                $ends[$e['name']] = $i;
            }
        }

        self::assertArrayHasKey('pipeline', $ends, 'the outer trace was cut short before its own span closed');
        self::assertArrayHasKey('request', $ends);
        self::assertLessThan(
            $ends['request'],
            $ends['pipeline'],
            'end:pipeline must be recorded before end:request',
        );
    }

    #[Test]
    public function a_finished_trace_leaves_the_query_log_switched_off(): void
    {
        $this->marker = '1';

        $this->runRequest(new RequestTracer());

        self::assertFalse(QueryRecorder::isRecording(), 'the query log outlived the request that opened it');
    }

    /**
     * The leak the review found: a buffer that fails mid-trace returned from
     * `end()` before the query log was released, and the log then went on
     * collecting every statement the worker ran for the rest of its life.
     */
    #[Test]
    public function a_trace_that_fails_still_switches_the_query_log_off(): void
    {
        $this->marker = '1';
        $tracer = new RequestTracer();

        $tracer->begin('request', ['method' => 'GET', 'path' => '/x', 'marker' => '1']);
        $buffer = TraceContext::current();
        self::assertInstanceOf(TraceBuffer::class, $buffer);
        $buffer->failed = true;
        $tracer->end('request');

        self::assertFalse(QueryRecorder::isRecording(), 'a failed trace left the query log enabled');
        self::assertSame([], $this->traceFiles(), 'a failed trace must not be written');
    }

    /**
     * Failure is a property of one recording, not of the shared tracer instance.
     */
    #[Test]
    public function a_failed_trace_does_not_disable_the_next_one(): void
    {
        $this->marker = '1';
        $tracer = new RequestTracer();

        $tracer->begin('request', ['method' => 'GET', 'path' => '/first', 'marker' => '1']);
        $buffer = TraceContext::current();
        self::assertInstanceOf(TraceBuffer::class, $buffer);
        $buffer->failed = true;
        $tracer->end('request');

        $this->runRequest($tracer);

        $files = $this->traceFiles();
        self::assertCount(1, $files, 'the request after a failed one was not recorded');
    }

    /**
     * A long SSE connection can reach the event cap before its root span closes,
     * and the dropped event is the one carrying the total. Without a total the
     * viewer scales every bar against zero.
     */
    #[Test]
    public function a_trace_that_hits_the_event_cap_still_reports_how_long_it_ran(): void
    {
        $this->marker = '1';
        $tracer = new RequestTracer();

        $tracer->begin('request', ['method' => 'GET', 'path' => '/long', 'marker' => '1']);
        for ($i = 0; $i < TraceBuffer::MAX_EVENTS + 10; $i++) {
            $tracer->mark('tick');
        }
        $tracer->end('request');

        $trace = json_decode((string) file_get_contents($this->traceFiles()[0]), true);
        self::assertTrue($trace['truncated'], 'the trace does not say it was cut off');
        self::assertGreaterThan(0.0, $trace['totalMs'], 'elapsed time was lost with the dropped end event');

        $names = array_column($trace['events'], 'name');
        self::assertNotContains('request', array_slice($names, 1), 'the end event survived, so this is not the capped case');
    }

    #[Test]
    public function queries_attach_live_to_the_span_that_ran_them(): void
    {
        // The defect this pins (review on dev#45): queries used to be drained at
        // root close, so every one carried atMs null and a hardcoded depth - the
        // trace could not say which span ran a query, which is the question an
        // N+1 is opened to answer.
        $this->marker = '1';
        $tracer = new RequestTracer();
        $tracer->begin('request', ['method' => 'GET', 'path' => '/q', 'marker' => '1']);
        $tracer->begin('pipeline');
        QueryRecorder::record('SELECT * FROM widget', ['a'], 1.5);
        $tracer->end('pipeline', ['handler' => 'H']);
        $tracer->end('request');

        $events = json_decode((string) file_get_contents($this->traceFiles()[0]), true)['events'];
        $byName = [];
        foreach ($events as $i => $e) {
            $byName[$e['name']][] = $i;
        }

        self::assertArrayHasKey('orm.query', $byName, 'the query never reached the trace');
        $q = $events[$byName['orm.query'][0]];
        self::assertNotNull($q['atMs'], 'a live-attached query must know WHEN it ran');
        self::assertArrayHasKey('cid', $q, 'a live-attached query must know WHERE it ran');
        self::assertSame(1.5, $q['durationMs']);
        self::assertLessThan(
            $byName['pipeline'][1],
            $byName['orm.query'][0],
            'the query must sit inside the pipeline span, not after the trace',
        );

        $summary = $events[$byName['orm.summary'][0]];
        self::assertSame(1, $summary['context']['queries']);
    }

    #[Test]
    public function every_dev_request_lands_in_the_observatory_journal_marked_or_not(): void
    {
        // No marker on purpose: the journal is the always-on live view, the
        // trace file is the opt-in deep view, and only the second needs the flag.
        $this->runRequest(new RequestTracer());

        self::assertSame([], $this->traceFiles(), 'an unmarked request must still leave no trace file');

        $lines = $this->journalLines();
        self::assertCount(2, $lines, 'one begin and one end line');
        [$begin, $end] = $lines;
        self::assertSame('begin', $begin['event']);
        self::assertSame('end', $end['event']);
        self::assertSame($begin['id'], $end['id'], 'begin and end must describe the same process');
        self::assertSame('http', $begin['kind']);
        self::assertSame('HubPayload', $begin['name'], 'the route names the process');
        self::assertIsNumeric($end['durationMs']);
        self::assertArrayNotHasKey('trace', $end, 'no trace file was written, so none may be linked');
    }

    #[Test]
    public function a_marked_request_journal_links_its_trace_file(): void
    {
        $this->marker = '1';
        $this->runRequest(new RequestTracer());

        [, $end] = $this->journalLines();
        self::assertArrayHasKey('trace', $end);
        self::assertSame(basename($this->traceFiles()[0]), $end['trace']);
    }

    #[Test]
    public function the_journal_is_silent_outside_dev(): void
    {
        putenv('APP_ENV=production');

        $this->runRequest(new RequestTracer());

        self::assertSame([], $this->journalLines(), 'production must never write the journal');
    }

    #[Test]
    public function a_nested_root_span_is_one_journal_process_not_two(): void
    {
        $tracer = new RequestTracer();
        $tracer->begin('request', ['method' => 'GET', 'path' => '/outer', 'marker' => null]);

        $inner = new RequestTracer();
        $inner->begin('request', ['method' => 'GET', 'path' => '/inner', 'marker' => null]);
        $inner->end('request');

        $tracer->end('request');

        $lines = $this->journalLines();
        self::assertCount(2, $lines, 'an internal re-dispatch is nested work, not a second process');
        self::assertSame('/outer', $lines[0]['context']['path'] ?? $lines[0]['name']);
    }

    #[Test]
    public function a_payload_snapshot_lands_in_the_trace_redacted(): void
    {
        $dto = new class {
            public string $email = 'a@b.c';
            public string $password = 'hunter2';
        };

        $this->marker = '1';
        $tracer = new RequestTracer();
        $tracer->begin('request', ['method' => 'POST', 'path' => '/login', 'marker' => '1']);
        $tracer->begin('payload.hydrate_and_validate', ['payload' => $dto::class]);
        $tracer->end('payload.hydrate_and_validate', ['rejected' => false, 'payload_snapshot' => $dto]);
        $tracer->end('request');

        $raw = (string) file_get_contents($this->traceFiles()[0]);
        self::assertStringNotContainsString('hunter2', $raw, 'the secret must never reach disk');

        $events = json_decode($raw, true)['events'];
        $hydrate = null;
        foreach ($events as $e) {
            if ($e['type'] === 'end' && $e['name'] === 'payload.hydrate_and_validate') {
                $hydrate = $e;
            }
        }
        self::assertNotNull($hydrate);
        self::assertSame('a@b.c', $hydrate['context']['snapshot']['email'], 'benign payload state is divable');
        self::assertSame('[redacted]', $hydrate['context']['snapshot']['password']);
    }

    #[Test]
    public function query_bindings_reach_the_trace_redacted_by_key(): void
    {
        $this->marker = '1';
        $tracer = new RequestTracer();
        $tracer->begin('request', ['method' => 'GET', 'path' => '/q', 'marker' => '1']);
        QueryRecorder::record('UPDATE users SET password = :password WHERE id = :id', ['password' => 'hunter2', 'id' => 'u-1'], 1.0);
        $tracer->end('request');

        $raw = (string) file_get_contents($this->traceFiles()[0]);
        self::assertStringNotContainsString('hunter2', $raw, 'a bound secret must never reach disk');

        $events = json_decode($raw, true)['events'];
        $query = null;
        foreach ($events as $e) {
            if ($e['type'] === 'query') {
                $query = $e;
            }
        }
        self::assertNotNull($query);
        self::assertSame('[redacted]', $query['context']['bindings']['password']);
        self::assertSame('u-1', $query['context']['bindings']['id'], 'the value a wrong-row hunt needs is there');
    }

    #[Test]
    public function a_background_job_root_journals_its_kind_and_opens_no_trace(): void
    {
        // Scheduler runs and queue consumers report as 'job' roots: journal
        // lifecycle yes, trace buffer no — spans belong to requests for now.
        $tracer = new RequestTracer();
        $tracer->begin('job', ['kind' => 'scheduler', 'route' => 'App\\Jobs\\NightlyRollup', 'attempt' => 2]);
        $tracer->end('job', ['status' => 'success']);

        self::assertSame([], $this->traceFiles(), 'a job root must not open a trace buffer');

        $lines = $this->journalLines();
        self::assertCount(2, $lines);
        self::assertSame('scheduler', $lines[0]['kind']);
        self::assertSame('App\\Jobs\\NightlyRollup', $lines[0]['name']);
        self::assertSame('success', $lines[1]['context']['status'] ?? null);
        self::assertIsNumeric($lines[1]['durationMs']);
    }

    #[Test]
    public function journal_files_past_retention_are_swept_on_write(): void
    {
        $dir = $this->root . '/var/observatory';
        mkdir($dir, 0755, true);
        $ancient = $dir . '/journal-' . date('Ymd', time() - 30 * 86400) . '.ndjson';
        $recent = $dir . '/journal-' . date('Ymd', time() - 2 * 86400) . '.ndjson';
        file_put_contents($ancient, "{}\n");
        file_put_contents($recent, "{}\n");
        // The sweep memoizes per day per process; other tests may have latched
        // it already, so reset via reflection to exercise it here.
        $prop = new \ReflectionProperty(\Semitexa\Dev\Application\Service\Trace\ObservatoryJournal::class, 'sweptDay');
        $prop->setValue(null, null);

        $this->runRequest(new RequestTracer());

        self::assertFileDoesNotExist($ancient, 'a 30-day-old journal must be swept');
        self::assertFileExists($recent, 'a file inside the retention window must survive');
    }

    #[Test]
    public function the_observatory_does_not_observe_itself(): void
    {
        // The panel polls its own feed every second; journaling those polls
        // would flood the journal with the act of watching it.
        $tracer = new RequestTracer();
        $tracer->begin('request', ['method' => 'GET', 'path' => '/__observatory/feed', 'marker' => null]);
        $tracer->end('request');

        self::assertSame([], $this->journalLines(), 'feed polls must never reach the journal');
    }

    /** @return list<array<string, mixed>> */
    private function journalLines(): array
    {
        $files = glob($this->root . '/var/observatory/journal-*.ndjson') ?: [];
        $lines = [];
        foreach ($files as $f) {
            foreach (explode("\n", trim((string) file_get_contents($f))) as $line) {
                if ($line !== '') {
                    $lines[] = json_decode($line, true);
                }
            }
        }

        return $lines;
    }

    /**
     * A minimal stand-in for the RouteExecutor call sequence.
     */
    private function runRequest(RequestTracer $tracer): void
    {
        $tracer->begin('request', ['method' => 'GET', 'path' => '/playground', 'route' => 'HubPayload', 'marker' => $this->marker]);
        $tracer->mark('auth.pre_hydration_gate.absent');
        $tracer->begin('payload.hydrate_and_validate', ['payload' => 'App\\HubPayload']);
        $tracer->end('payload.hydrate_and_validate', ['rejected' => false]);
        $tracer->begin('resource.resolve');
        $tracer->end('resource.resolve', ['resource' => 'App\\HubResponse']);
        $tracer->begin('pipeline');
        $tracer->end('pipeline', ['handler' => 'App\\HubHandler']);
        $tracer->begin('response.render');
        $tracer->end('response.render');
        $tracer->end('request');
    }

    /** @return list<string> */
    private function traceFiles(): array
    {
        return array_values(glob($this->root . '/var/trace/*.json') ?: []);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
