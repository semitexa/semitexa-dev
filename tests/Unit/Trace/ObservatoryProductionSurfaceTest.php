<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryFeedHandler;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryHandler;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatorySchedulesHandler;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryStageHandler;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryStreamHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryFeedPayload;
use Semitexa\Dev\Application\Payload\Request\ObservatoryPayload;
use Semitexa\Dev\Application\Payload\Request\ObservatorySchedulesPayload;
use Semitexa\Dev\Application\Payload\Request\ObservatoryStagePayload;
use Semitexa\Dev\Application\Payload\Request\ObservatoryStreamPayload;
use Semitexa\Dev\Application\Service\Trace\CoroutineSnapshot;
use Semitexa\Dev\Application\Service\Trace\ObservatoryContext;
use Semitexa\Dev\Application\Service\Trace\ObservatoryHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;
use Semitexa\Dev\Application\Service\Trace\ObservatoryStage;
use Semitexa\Dev\Application\Service\Trace\RequestTracer;
use Semitexa\Dev\Application\Service\Trace\ScheduleCatalog;
use Semitexa\Dev\Application\Service\Trace\TraceContext;

/**
 * semitexa/dev ships inside semitexa/ultimate, so this code IS present on a
 * production box. What keeps it invisible there is APP_ENV, and this test is
 * the contract: outside dev every Observatory surface is a 404 and nothing is
 * written to disk — no journal, no coroutine snapshot, no stage flag.
 */
final class ObservatoryProductionSurfaceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semitexa-obs-prod-' . uniqid();
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
        TraceContext::resetFallback();
        ObservatoryContext::reset();
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_OBSERVATORY_DIR');
        TraceContext::resetFallback();
        ObservatoryContext::reset();
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->dir);
        }
    }

    #[Test]
    public function every_surface_is_a_404_outside_dev(): void
    {
        $gate = new ObservatoryPanelGate();
        $reader = new ObservatoryReader();
        $stage = new ObservatoryStagePayload();
        $stage->setOn('1');
        $feed = new ObservatoryFeedPayload();
        $feed->setStream('1');

        $codes = [
            'panel' => $this->wire(new ObservatoryHandler(), ['gate' => $gate, 'renderer' => new ObservatoryHtmlRenderer()])->handle(new ObservatoryPayload(), new ResourceResponse())->getStatusCode(),
            'feed' => $this->wire(new ObservatoryFeedHandler(), ['gate' => $gate, 'reader' => $reader])->handle($feed, new ResourceResponse())->getStatusCode(),
            'stage' => $this->wire(new ObservatoryStageHandler(), ['gate' => $gate])->handle($stage, new ResourceResponse())->getStatusCode(),
            'schedules' => $this->wire(new ObservatorySchedulesHandler(), ['gate' => $gate, 'catalog' => new ScheduleCatalog()])->handle(new ObservatorySchedulesPayload(), new ResourceResponse())->getStatusCode(),
            'stream' => $this->wire(new ObservatoryStreamHandler(), ['gate' => $gate, 'reader' => $reader])->handle(new ObservatoryStreamPayload(), new ResourceResponse())->getStatusCode(),
        ];

        self::assertSame(['panel' => 404, 'feed' => 404, 'stage' => 404, 'schedules' => 404, 'stream' => 404], $codes);
        self::assertFalse(ObservatoryStage::isOn(), 'a POST that was refused must not have flipped the flag');
        self::assertFileDoesNotExist(ObservatoryStage::path());
    }

    #[Test]
    public function nothing_is_written_to_disk_outside_dev(): void
    {
        $tracer = new RequestTracer();
        $tracer->begin('request', ['method' => 'GET', 'path' => '/', 'route' => 'Home', 'marker' => '1']);
        $tracer->end('request');
        CoroutineSnapshot::maybeWrite();

        self::assertDirectoryDoesNotExist($this->dir, 'no journal, no snapshot, no trace — the directory is never even created');
    }

    /** @param array<string, object> $props */
    private function wire(object $handler, array $props): object
    {
        foreach ($props as $name => $value) {
            (new \ReflectionProperty($handler, $name))->setValue($handler, $value);
        }

        return $handler;
    }
}
