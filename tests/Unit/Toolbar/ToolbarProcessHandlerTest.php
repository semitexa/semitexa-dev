<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Toolbar;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Dev\Application\Handler\PayloadHandler\ToolbarProcessHandler;
use Semitexa\Dev\Application\Payload\Request\ToolbarProcessPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

final class ToolbarProcessHandlerTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'SEMITEXA_OBSERVATORY_MODE', 'SEMITEXA_OBSERVATORY_TOKEN'] as $key) {
            $this->saved[$key] = getenv($key);
        }
    }

    protected function tearDown(): void
    {
        CurrentRequestStore::clear();
        foreach ($this->saved as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
    }

    #[Test]
    public function a_page_on_another_site_cannot_read_a_process(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=');
        $handler = new ToolbarProcessHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());
        // This machine's browser, fetched by another site's page.
        CurrentRequestStore::set(new Request('GET', '/__toolbar/process/p-1-abcd', [
            'Host' => 'localhost:9502',
            'Sec-Fetch-Site' => 'cross-site',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
        ], [], [], ['remote_addr' => '127.0.0.1'], []));
        $payload = new ToolbarProcessPayload();
        $payload->setId('p-1-abcd');

        self::assertSame(404, $handler->handle($payload, new ResourceResponse())->getStatusCode());
    }
}
