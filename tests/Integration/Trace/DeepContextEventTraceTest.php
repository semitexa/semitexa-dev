<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\ContainerFactory;
use Semitexa\Core\Event\EventDispatcherInterface;
use Semitexa\Core\Pipeline\RequestTracerInterface;
use Semitexa\Dev\Application\Service\Trace\TraceContext;

/**
 * Deep-context integration over the REAL container: the EventDispatcher must
 * report into whatever tracer the container carries, so a marked trace shows
 * which events fired during the request. Uses the real discovery-built wiring
 * on purpose — a fake dispatcher would pin nothing about the seam this tests.
 */
final class DeepContextEventTraceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-deep-ctx-' . uniqid();
        mkdir($this->root, 0755, true);
        TraceContext::resetFallback();
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_TRACE_DIR=' . $this->root . '/trace');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->root . '/observatory');
    }

    protected function tearDown(): void
    {
        TraceContext::resetFallback();
        putenv('APP_ENV');
        putenv('SEMITEXA_TRACE_DIR');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        foreach (glob($this->root . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->root . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->root);
    }

    #[Test]
    public function a_consumed_queue_message_journals_as_a_queue_job(): void
    {
        $worker = new \Semitexa\Core\Queue\QueueWorker();
        $worker->processPayload((string) json_encode([
            'type' => 'handler',
            'handlerClass' => 'App\\DoesNotExist\\Handler',
        ]));

        $lines = [];
        foreach (glob($this->root . '/observatory/journal-*.ndjson') ?: [] as $f) {
            foreach (explode("\n", trim((string) file_get_contents($f))) as $line) {
                if ($line !== '') {
                    $lines[] = json_decode($line, true);
                }
            }
        }

        self::assertCount(2, $lines, 'one consumed message is one journal process');
        self::assertSame('queue', $lines[0]['kind']);
        self::assertSame('App\\DoesNotExist\\Handler', $lines[0]['name']);
        self::assertSame($lines[0]['id'], $lines[1]['id']);
    }

    #[Test]
    public function a_dispatched_event_lands_in_the_open_trace(): void
    {
        $container = ContainerFactory::get();
        self::assertTrue(
            $container->has(RequestTracerInterface::class),
            'the workspace registers the dev tracer; without it this test pins nothing',
        );

        $tracer = $container->get(RequestTracerInterface::class);
        $dispatcher = $container->get(EventDispatcherInterface::class);

        $tracer->begin('request', ['method' => 'GET', 'path' => '/evt', 'marker' => '1']);
        $dispatcher->dispatch(new class {
        });
        $tracer->end('request');

        $files = glob($this->root . '/trace/*.json') ?: [];
        self::assertCount(1, $files);

        $events = json_decode((string) file_get_contents($files[0]), true)['events'];
        $dispatchMarks = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['name'] === 'event.dispatch',
        ));

        self::assertNotEmpty($dispatchMarks, 'the dispatcher must report into the open trace');
        self::assertStringContainsString('class@anonymous', (string) $dispatchMarks[0]['context']['event']);
        self::assertSame(0, $dispatchMarks[0]['context']['listeners'], 'an unknown event has no listeners, and says so');
    }
}
