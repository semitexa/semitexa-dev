<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\RequestTracer;
use Semitexa\Dev\Application\Service\Trace\TraceContext;
use Swoole\Coroutine\Channel;

use function Swoole\Coroutine\run;

/**
 * What the tracer does when two requests run at the same time.
 *
 * The unit tests run without coroutines, where the buffer falls back to a single
 * process-local slot — so they can pin sequencing but not concurrency. These
 * cases need the real thing: separate coroutines with a channel barrier, so the
 * interleaving is fixed rather than left to timing.
 *
 * The tracer is one instance for the whole worker. Every property below is about
 * two requests sharing it and not seeing each other.
 */
final class RequestTracerCoroutineIsolationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole is required to run coroutines.');
        }

        $this->root = sys_get_temp_dir() . '/semitexa-tracer-coro-' . uniqid();
        mkdir($this->root . '/var/trace', 0755, true);
        TraceContext::resetFallback();
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_TRACE_DIR=' . $this->root . '/var/trace');
    }

    protected function tearDown(): void
    {
        TraceContext::resetFallback();
        putenv('APP_ENV');
        putenv('SEMITEXA_TRACE_DIR');
        $this->removeDir($this->root);
    }

    /**
     * The scenario the single-slot fallback cannot express: an untraced request
     * running beside a traced one, sharing the tracer instance, must neither
     * record into the other's trace nor close it.
     */
    #[Test]
    public function an_untraced_request_beside_a_traced_one_leaves_it_alone(): void
    {
        $tracer = new RequestTracer();
        $opened = new Channel(1);
        $finished = new Channel(1);

        run(static function () use ($tracer, $opened, $finished): void {
            go(static function () use ($tracer, $opened, $finished): void {
                $tracer->begin('request', ['method' => 'GET', 'path' => '/traced', 'marker' => '1']);
                $tracer->begin('pipeline');
                $opened->push(true);

                // Hold the span open until the untraced request has run its whole
                // course beside it.
                $finished->pop(5);

                $tracer->end('pipeline', ['handler' => 'H']);
                $tracer->end('request');
            });

            go(static function () use ($tracer, $opened, $finished): void {
                $opened->pop(5);
                $tracer->begin('request', ['method' => 'GET', 'path' => '/other', 'marker' => null]);
                $tracer->begin('pipeline');
                $tracer->end('pipeline', ['handler' => 'Other']);
                $tracer->end('request');
                $finished->push(true);
            });
        });

        $files = $this->traceFiles();
        self::assertCount(1, $files, 'only the marked request writes a trace');

        $trace = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($trace);
        $events = $trace['events'];

        self::assertSame('/traced', $events[0]['context']['path']);

        $handlers = [];
        foreach ($events as $e) {
            if ($e['type'] === 'end' && $e['name'] === 'pipeline') {
                $handlers[] = $e['context']['handler'] ?? null;
            }
        }
        self::assertSame(['H'], $handlers, 'the untraced request recorded into the traced buffer');

        $ends = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['type'] === 'end',
        ));
        self::assertSame('request', $ends[count($ends) - 1]['name'], 'the trace was closed by the wrong request');
    }

    /**
     * Two coroutines inside ONE trace, both opening a span of the same name — the
     * shape an SSE connection makes when it streams several deferred blocks.
     *
     * Keyed by name alone, the second begin overwrote the first start time, the
     * first end consumed it, and the second end reported no duration at all.
     */
    #[Test]
    public function two_coroutines_opening_the_same_span_each_get_their_own_duration(): void
    {
        $tracer = new RequestTracer();
        $bothOpen = new Channel(2);
        $mayClose = new Channel(2);

        run(static function () use ($tracer, $bothOpen, $mayClose): void {
            $tracer->begin('sse', ['sse' => true, 'path' => '/__semitexa_kiss']);

            foreach (['a', 'b'] as $which) {
                go(static function () use ($tracer, $bothOpen, $mayClose, $which): void {
                    $tracer->begin('deferred.block', ['block' => $which]);
                    $bothOpen->push($which);
                    $mayClose->pop(5);
                    $tracer->end('deferred.block', ['block' => $which]);
                });
            }

            // Both spans are open at once before either closes: that overlap is
            // the whole point.
            $bothOpen->pop(5);
            $bothOpen->pop(5);
            $mayClose->push(true);
            $mayClose->push(true);

            $tracer->end('sse');
        });

        $files = $this->traceFiles();
        self::assertCount(1, $files);

        $events = json_decode((string) file_get_contents($files[0]), true)['events'];

        $blocks = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['name'] === 'deferred.block' && $e['type'] === 'end',
        ));
        self::assertCount(2, $blocks, 'both coroutines closed their span');

        foreach ($blocks as $end) {
            self::assertNotNull(
                $end['durationMs'],
                'a span opened in one coroutine was paired with another coroutine\'s begin',
            );
        }

        $cids = array_unique(array_column($blocks, 'cid'));
        self::assertCount(2, $cids, 'the two spans must be attributed to different coroutines');
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

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
