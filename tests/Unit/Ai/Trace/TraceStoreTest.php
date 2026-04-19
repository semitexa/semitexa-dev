<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Trace;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceHeader;
use Semitexa\Dev\Ai\Trace\TraceStore;

/**
 * TraceStore is now a constructor-less #[AsService] that resolves its
 * project root from {@see ProjectRoot::get()} — tests chdir into a throw-
 * away dir with stub `composer.json`/`src/modules` so the resolver locks
 * onto it, then `ProjectRoot::reset()` keeps the cache from leaking.
 */
class TraceStoreTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-trace-' . uniqid();
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        $this->removeDir($this->root);
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

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
