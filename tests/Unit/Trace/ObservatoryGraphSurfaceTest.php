<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryAssetHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryAssetPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ObservatoryTopology;

/**
 * The Graph view's surfaces besides its data endpoint.
 *
 * The rule these pin down: in monitor mode — the production mode, even with
 * its token — the viewer's assets are a 404 like an unknown file, and the
 * panel offers no switch, no graph section and links no viewer asset; in dev
 * mode all three are there and the assets are served.
 */
final class ObservatoryGraphSurfaceTest extends TestCase
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
    public function monitor_mode_serves_no_viewer_and_offers_no_switch(): void
    {
        $this->monitorWithToken();

        foreach (['graph-view.js', 'graph-view.css'] as $name) {
            self::assertSame(404, $this->asset($name)->getStatusCode(), $name);
        }
        self::assertSame(200, $this->asset('observatory.js')->getStatusCode(), 'the panel itself still loads');

        $html = $this->page();
        self::assertStringContainsString('<script src="/__observatory/asset/observatory.js"></script>', $html, 'the panel rendered');
        self::assertStringNotContainsString('data-mode="graph"', $html);
        self::assertStringNotContainsString('id="view-graph"', $html);
        self::assertStringNotContainsString('graph-view.', $html);
    }

    #[Test]
    public function dev_mode_serves_the_viewer_and_offers_the_switch(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');

        $js = $this->asset('graph-view.js');
        self::assertSame(200, $js->getStatusCode());
        self::assertStringContainsString('SemitexaGraphView', (string) $js->getContent());

        $html = $this->page();
        self::assertStringContainsString('data-mode="graph"', $html);
        self::assertStringContainsString('id="view-graph"', $html);
        self::assertStringContainsString('/__observatory/asset/graph-view.js', $html);
    }

    #[Test]
    public function settings_popover_is_a_plain_disclosure_not_an_aria_menu(): void
    {
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');

        // Its children are ordinary buttons with no arrow-key model; role="menu" would promise one.
        $html = $this->page();
        self::assertStringContainsString('<details class="settings" id="settings">', $html);
        self::assertStringNotContainsString('role="menu"', $html);
    }

    private function monitorWithToken(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        CurrentRequestStore::set(new Request(
            method: 'GET',
            uri: '/__observatory',
            headers: ['X-Observatory-Token' => 's3cret'],
            query: [],
            post: [],
            server: ['remote_addr' => '127.0.0.1'],
            cookies: [],
        ));
    }

    private function asset(string $name): ResourceResponse
    {
        $handler = new ObservatoryAssetHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());
        $payload = new ObservatoryAssetPayload();
        $payload->setName($name);

        return $handler->handle($payload, new ResourceResponse());
    }

    private function page(): string
    {
        $renderer = new ObservatoryHtmlRenderer();
        (new \ReflectionProperty($renderer, 'topology'))->setValue($renderer, new ObservatoryTopology());

        return $renderer->render();
    }
}
