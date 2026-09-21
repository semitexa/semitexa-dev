<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Trace;

use PHPUnit\Framework\Attributes\DataProvider;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceHeader;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Tests\Support\CapturingLogger;
use Semitexa\Dev\Tests\Support\TraceWriteFaultStream;
use Semitexa\Testing\TestCase;

/**
 * TraceStore is a constructor-less #[AsService] that resolves its project
 * root from ProjectRoot::get() — the base TestCase's fixture root points the
 * resolver at a throwaway dir and cleans up cwd + memoized root afterwards.
 */
class TraceStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->enterFixtureProjectRoot('trace');
    }

    public function test_open_or_create_writes_header_line_with_schema_version(): void
    {
        $store = new TraceStore();
        $header = $store->openOrCreate('my-trace', 'Ship feature X', 'add_authenticated_json_route');

        $this->assertSame(TraceHeader::SCHEMA_VERSION, $header->schemaVersion);
        $this->assertSame('my-trace', $header->traceId);
        $this->assertSame('Ship feature X', $header->topic);
        $this->assertSame('add_authenticated_json_route', $header->recipe);
        $this->assertFileExists($store->pathFor('my-trace'));

        $lines = file($store->pathFor('my-trace'));
        $this->assertCount(1, $lines);
        $decoded = json_decode(trim($lines[0]), true);
        $this->assertSame('header', $decoded['kind']);
        $this->assertSame(TraceHeader::SCHEMA_VERSION, $decoded['schema_version']);
    }

    public function test_open_or_create_is_idempotent_and_does_not_rewrite_topic(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t', 'original topic');
        $header = $store->openOrCreate('t', 'different topic on reopen');

        $this->assertSame('original topic', $header->topic);
        $this->assertCount(1, file($store->pathFor('t')));
    }

    public function test_append_assigns_monotonic_event_ids(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t');
        $a = $store->append('t', TraceEventKind::TASK_RESULT, 'first');
        $b = $store->append('t', TraceEventKind::VERIFY_RESULT, 'second');
        $c = $store->append('t', TraceEventKind::NEXT_STEP, 'third');

        $this->assertSame(1, $a->eventId);
        $this->assertSame(2, $b->eventId);
        $this->assertSame(3, $c->eventId);
    }

    public function test_append_preserves_payload_round_trip(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t');
        $store->append('t', TraceEventKind::PLAN_DECISION, 'risk high', [
            'score'   => 42,
            'reasons' => ['contract changed', 'many files'],
        ]);

        $trace = $store->read('t');
        $this->assertCount(1, $trace->events);
        $this->assertSame(42, $trace->events[0]->payload['score']);
        $this->assertSame(['contract changed', 'many files'], $trace->events[0]->payload['reasons']);
    }

    public function test_read_returns_header_and_ordered_events(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t', 'topic');
        $store->append('t', TraceEventKind::TASK_RESULT, 'a');
        $store->append('t', TraceEventKind::VERIFY_RESULT, 'b');
        $store->append('t', TraceEventKind::NEXT_STEP, 'c');

        $trace = $store->read('t');
        $this->assertSame('topic', $trace->header->topic);
        $this->assertSame([1, 2, 3], array_map(static fn($e) => $e->eventId, $trace->events));
        $this->assertSame(['a', 'b', 'c'], array_map(static fn($e) => $e->summary, $trace->events));
    }

    public function test_append_on_missing_trace_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        (new TraceStore())->append('never-started', TraceEventKind::NOTE, 'hi');
    }

    public function test_read_on_missing_trace_fails(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TraceStore())->read('never-started');
    }

    public function test_invalid_trace_id_rejected(): void
    {
        $store = new TraceStore();
        $this->expectException(\InvalidArgumentException::class);
        $store->openOrCreate('Bad ID With Spaces');
    }

    public function test_trace_id_rejects_path_traversal(): void
    {
        $store = new TraceStore();
        $this->expectException(\InvalidArgumentException::class);
        $store->openOrCreate('../../etc/passwd');
    }

    public function test_list_enumerates_every_trace_sorted_by_created_at(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('alpha');
        usleep(10_000);
        $store->openOrCreate('beta');
        usleep(10_000);
        $store->openOrCreate('gamma');

        $ids = array_map(static fn($h) => $h->traceId, $store->list());
        $this->assertSame(['alpha', 'beta', 'gamma'], $ids);
    }

    public function test_list_ignores_non_ndjson_files(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('real');
        file_put_contents($this->root . '/var/ai-traces/README.md', 'hello');

        $ids = array_map(static fn($h) => $h->traceId, $store->list());
        $this->assertSame(['real'], $ids);
    }

    public function test_exists_returns_false_before_start_and_true_after(): void
    {
        $store = new TraceStore();
        $this->assertFalse($store->exists('t'));
        $store->openOrCreate('t');
        $this->assertTrue($store->exists('t'));
    }

    public function test_ndjson_on_disk_has_one_json_object_per_line(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t');
        $store->append('t', TraceEventKind::NOTE, 'one');
        $store->append('t', TraceEventKind::NOTE, 'two');

        $contents = (string) file_get_contents($store->pathFor('t'));
        $lines = array_values(array_filter(explode("\n", $contents)));
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertNotFalse(json_decode($line, true), "line parses as JSON: {$line}");
        }
    }

    public function test_list_logs_and_skips_a_corrupt_trace_instead_of_silently_dropping_it(): void
    {
        $store = new TraceStore();
        $store->openOrCreate('tr-ok', 'topic');

        // A partially-written trace: a malformed header line.
        file_put_contents($this->root . '/var/ai-traces/tr-bad.ndjson', "garbage not a header\n");

        $logger = new CapturingLogger();
        StaticLoggerBridge::set($logger);
        try {
            $headers = $store->list();
        } finally {
            StaticLoggerBridge::reset();
        }

        $this->assertSame(['tr-ok'], array_map(static fn(TraceHeader $h) => $h->traceId, $headers));
        $this->assertCount(1, $logger->warnings);
        [$message, $context] = $logger->warnings[0];
        $this->assertSame('Skipping unreadable trace record', $message);
        $this->assertStringContainsString('tr-bad.ndjson', (string) $context['file']);
    }

    #[DataProvider('unencodableEvents')]
    public function test_encoding_failure_does_not_modify_the_trace(string $summary, array $payload): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t');
        $store->append('t', TraceEventKind::NOTE, 'existing note');
        $before = file_get_contents($store->pathFor('t'));

        $error = null;
        try {
            $store->append('t', TraceEventKind::NOTE, $summary, $payload);
        } catch (\RuntimeException $e) {
            $error = $e;
        }

        $this->assertInstanceOf(\RuntimeException::class, $error, 'An unencodable event must fail, not append a blank line.');
        $this->assertInstanceOf(\JsonException::class, $error->getPrevious());
        $this->assertSame($before, file_get_contents($store->pathFor('t')));
        $this->assertSame(2, $store->append('t', TraceEventKind::NOTE, 'retry')->eventId);
        $this->assertSame(['existing note', 'retry'], array_column($store->read('t')->events, 'summary'));
    }

    public static function unencodableEvents(): iterable
    {
        yield 'summary' => ["invalid \xB1", []];
        yield 'payload' => ['valid summary', ['note' => "invalid \xB1"]];
        yield 'non-finite number' => ['valid summary', ['value' => INF]];
    }

    #[DataProvider('writeFailures')]
    public function test_write_failure_is_reported_and_does_not_poison_later_appends(?int $writeLimit, bool $failFlush): void
    {
        $store = new TraceStore();
        $store->openOrCreate('t');
        $store->append('t', TraceEventKind::NOTE, 'existing note');
        $path = $store->pathFor('t');
        $before = file_get_contents($path);
        $this->assertTrue(stream_wrapper_register('tracefault', TraceWriteFaultStream::class));
        TraceWriteFaultStream::$writeLimit = $writeLimit;
        TraceWriteFaultStream::$failFlush = $failFlush;
        (new \ReflectionProperty(ProjectRoot::class, 'root'))->setValue(null, 'tracefault://' . $this->root);
        $error = null;
        try {
            $store->append('t', TraceEventKind::NOTE, 'must not report success');
        } catch (\RuntimeException $e) {
            $error = $e;
        } finally {
            // The wrapper's fault switches are static: a filtered run that
            // stops here would hand the next test a write budget of -1.
            TraceWriteFaultStream::$writeLimit = null;
            TraceWriteFaultStream::$failFlush = false;
            ProjectRoot::reset();
            stream_wrapper_unregister('tracefault');
        }

        $this->assertInstanceOf(\RuntimeException::class, $error, 'A short write or failed flush must not return an event as saved.');
        $this->assertStringContainsString('failed to append', $error->getMessage());
        $this->assertSame($before, file_get_contents($path), 'A failed append must restore the previous NDJSON boundary.');
        $this->assertSame(2, $store->append('t', TraceEventKind::NOTE, 'retry')->eventId);
        $this->assertSame(['existing note', 'retry'], array_column($store->read('t')->events, 'summary'));
    }

    public static function writeFailures(): iterable
    {
        yield 'write returns false' => [-1, false];
        yield 'zero bytes' => [0, false];
        yield 'partial line' => [12, false];
        yield 'flush failure' => [null, true];
    }
}
