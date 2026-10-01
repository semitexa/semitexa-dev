<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryGraphHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryGraphPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;

/**
 * The Graph view's endpoint behind the dev-tools gate.
 *
 * The rule these pin down: the graph is a map of the application's internals,
 * so the production monitor mode — even with its token — gets the same 404 as
 * a page that does not exist, and the graph is never opened for it.
 */
final class ObservatoryGraphHandlerTest extends TestCase
{
    private const ENV_KEYS = ['APP_ENV', 'SEMITEXA_OBSERVATORY_MODE', 'SEMITEXA_OBSERVATORY_TOKEN'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        CurrentRequestStore::clear();
        foreach ($this->envSnapshot as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    #[Test]
    public function monitor_mode_with_its_token_gets_a_404_and_no_graph(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        CurrentRequestStore::set(new Request(
            method: 'GET',
            uri: '/__observatory/graph',
            headers: ['X-Observatory-Token' => 's3cret'],
            query: [],
            post: [],
            server: ['remote_addr' => '127.0.0.1'],
            cookies: [],
        ));

        // The graph reader is left unset: touching it would throw.
        $handler = new ObservatoryGraphHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());

        $response = $handler->handle(new ObservatoryGraphPayload(), new ResourceResponse());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('Not Found', $response->getContent());
    }

    #[Test]
    public function no_graph_is_a_404_that_says_so_never_a_5xx(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');

        $reader = $this->readerThatFailed(absent: true);

        $handler = new ObservatoryGraphHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());
        (new \ReflectionProperty($handler, 'graph'))->setValue($handler, $reader);

        $response = $handler->handle(new ObservatoryGraphPayload(), new ResourceResponse());

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('no-graph', json_decode((string) $response->getContent(), true)['error'] ?? null);
    }

    #[Test]
    public function a_graph_that_exists_but_cannot_be_read_is_a_503_not_build_one(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');

        $handler = new ObservatoryGraphHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());
        (new \ReflectionProperty($handler, 'graph'))->setValue($handler, $this->readerThatFailed(absent: false));

        $response = $handler->handle(new ObservatoryGraphPayload(), new ResourceResponse());

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('graph-unreadable', json_decode((string) $response->getContent(), true)['error'] ?? null);
    }

    /** A reader whose last open failed a moment ago, so it answers without retrying. */
    private function readerThatFailed(bool $absent): TraceGraphReader
    {
        $reader = (new \ReflectionClass(TraceGraphReader::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($reader, 'failedAt'))->setValue($reader, time());
        (new \ReflectionProperty($reader, 'failedBecauseAbsent'))->setValue($reader, $absent);

        return $reader;
    }

    #[Test]
    public function an_unknown_view_falls_back_to_the_summary(): void
    {
        $payload = new ObservatoryGraphPayload();
        $payload->setView('../../etc/passwd');
        self::assertSame('summary', $payload->view);

        $payload->setView('subgraph');
        self::assertSame('subgraph', $payload->view);
    }
}
