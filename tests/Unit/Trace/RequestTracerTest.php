<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\RequestTracer;

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
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_TRACE_DIR=' . $this->root . '/var/trace');
        $this->marker = null;
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
        putenv('SEMITEXA_TRACE_DIR');
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
