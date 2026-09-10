<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryStreamHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryStreamPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;

final class ObservatoryStreamHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_ENV=dev');
    }

    protected function tearDown(): void
    {
        putenv('APP_ENV');
    }

    /**
     * Outside a Swoole worker there is no connection to hold open. The
     * handler must say so in a way the panel can act on — a 503 with
     * {"sse":false} — rather than hang, throw, or pretend to stream.
     */
    #[Test]
    public function without_a_swoole_connection_it_declines_so_the_panel_falls_back_to_polling(): void
    {
        $handler = new ObservatoryStreamHandler();
        $this->inject($handler, 'reader', new ObservatoryReader());
        $this->inject($handler, 'gate', new ObservatoryPanelGate());

        $response = $handler->handle(new ObservatoryStreamPayload(), new ResourceResponse());

        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($response->isAlreadySent());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame(false, $body['sse'] ?? null);
    }

    #[Test]
    public function outside_dev_the_route_does_not_exist(): void
    {
        putenv('APP_ENV=prod');
        $handler = new ObservatoryStreamHandler();
        $this->inject($handler, 'reader', new ObservatoryReader());
        $this->inject($handler, 'gate', new ObservatoryPanelGate());

        self::assertSame(404, $handler->handle(new ObservatoryStreamPayload(), new ResourceResponse())->getStatusCode());
    }

    private function inject(object $target, string $property, object $value): void
    {
        $ref = new \ReflectionProperty($target, $property);
        $ref->setValue($target, $value);
    }
}
