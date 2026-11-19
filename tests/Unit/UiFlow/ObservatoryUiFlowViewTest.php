<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\UiFlow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Dev\Application\Handler\PayloadHandler\ObservatoryGraphHandler;
use Semitexa\Dev\Application\Payload\Request\ObservatoryGraphPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;
use Semitexa\Dev\Tests\Support\JsonRead;
use Semitexa\Dev\Tests\Support\UiFlowShop;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;

/**
 * The Observatory's `ui` view (tk-ui-flow-pr2-panel-and-verify): what the
 * "UI flow" tab draws, served by the graph endpoint from the model `ai:ask
 * ui-flow` reads.
 */
final class ObservatoryUiFlowViewTest extends TestCase
{
    private const ENV_KEYS = ['APP_ENV', 'SEMITEXA_OBSERVATORY_MODE', 'SEMITEXA_OBSERVATORY_TOKEN'];

    /** @var array<string, string|false> */
    private array $envSnapshot = [];

    private UiFlowShop $shop;

    protected function setUp(): void
    {
        foreach (self::ENV_KEYS as $key) {
            $this->envSnapshot[$key] = getenv($key);
        }
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        $this->shop = UiFlowShop::built();
    }

    protected function tearDown(): void
    {
        $this->shop->remove();
        CurrentRequestStore::clear();
        foreach ($this->envSnapshot as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    #[Test]
    public function the_view_opens_on_the_modules_that_have_screens(): void
    {
        [$status, $body] = $this->ui();

        self::assertSame(200, $status);
        self::assertSame(['ok', null, $this->shop->root], [$body['status'], $body['message'], $body['project_root']]);
        self::assertSame(
            [
                ['module' => 'Blog', 'screens' => 1, 'ways' => 2, 'unresolved' => 1],
                ['module' => 'Shop', 'screens' => 1, 'ways' => 3, 'unresolved' => 2],
            ],
            $body['modules'],
        );
        // Navigation and actions on; data and access named, and not drawn yet.
        self::assertSame(
            ['navigation' => ['available' => true, 'on' => true], 'action' => ['available' => true, 'on' => true], 'data' => ['available' => false, 'on' => false], 'access' => ['available' => false, 'on' => false]],
            $body['layers'],
        );
    }

    #[Test]
    public function a_module_is_its_screens_its_wires_and_a_ghost_for_each_other_module(): void
    {
        [$status, $body] = $this->ui(module: 'Shop');

        self::assertSame(200, $status);
        self::assertSame('Shop', $body['module']);
        self::assertSame(
            [[UiFlowShop::ORDERS, 'screen', '/orders', 0, 2, UiFlowShop::SHOP_SOURCE . ':9']],
            array_map(static fn (array $s): array => [$s['id'], $s['kind'], $s['path'], $s['depth'], $s['unresolved'], $s['at']], JsonRead::rows($body['screens'])),
        );
        self::assertSame(
            [['kind' => 'ghost', 'id' => 'module:Blog', 'module' => 'Blog', 'screens' => 1, 'ways_out' => 1, 'ways_in' => 1]],
            $body['ghosts'],
        );
        self::assertSame(
            [['from' => UiFlowShop::ORDERS, 'to' => 'module:Blog', 'layer' => 'navigation', 'count' => 1, 'confidence' => 'exact', 'shared' => false, 'tree' => false, 'at' => UiFlowShop::ORDERS_TEMPLATE . ':2']],
            $body['wires'],
        );
    }

    #[Test]
    public function a_screen_is_its_elements_with_one_hop_out_and_one_hop_in(): void
    {
        [$status, $body] = $this->ui(id: UiFlowShop::ORDERS);

        self::assertSame(200, $status);
        $screen = JsonRead::map($body['screen']);
        self::assertSame([UiFlowShop::ORDERS, 'Campaign\\Shop\\OrdersPayload'], [$screen['id'], $screen['fqcn']]);
        self::assertSame(
            [
                ['/nowhere', 'unresolved', null, 'no route of that path', UiFlowShop::ORDERS_TEMPLATE . ':1'],
                ['/blog', 'exact', UiFlowShop::BLOG, null, UiFlowShop::ORDERS_TEMPLATE . ':2'],
                ['{{ row.href }}', 'unresolved', null, 'computed', UiFlowShop::ORDERS_TEMPLATE . ':3'],
            ],
            array_map(static fn (array $e): array => [$e['href'], $e['confidence'], $e['to'], $e['why'], $e['at']], JsonRead::rows($body['elements'])),
        );
        self::assertSame([[UiFlowShop::BLOG, 'Blog', 1, ['navigation']]], array_map(static fn (array $h): array => [JsonRead::map($h['node'])['id'], JsonRead::map($h['node'])['module'], $h['count'], $h['layers']], JsonRead::rows($body['out'])));
        self::assertSame([[UiFlowShop::BLOG, '/blog', 1]], array_map(static fn (array $h): array => [JsonRead::map($h['node'])['id'], JsonRead::map($h['node'])['path'], $h['count']], JsonRead::rows($body['in'])));
    }

    #[Test]
    public function a_screen_or_a_module_the_graph_does_not_have_is_a_404(): void
    {
        [$status, $body] = $this->ui(module: 'Billing');
        self::assertSame([404, 'no-node', 'Billing'], [$status, $body['error'], $body['id']]);

        [$status, $body] = $this->ui(id: 'class:Campaign\\Shop\\ArchiveOrdersPayload');
        self::assertSame([404, 'no-node'], [$status, $body['error']]);
    }

    #[Test]
    public function no_graph_is_still_the_404_that_says_so(): void
    {
        $reader = (new \ReflectionClass(TraceGraphReader::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($reader, 'failedAt'))->setValue($reader, time());
        (new \ReflectionProperty($reader, 'failedBecauseAbsent'))->setValue($reader, true);

        [$status, $body] = $this->ask($reader, ['view' => 'ui']);

        self::assertSame([404, 'no-graph'], [$status, $body['error']]);
    }

    #[Test]
    public function a_graph_built_on_another_machine_answers_with_that_status_not_with_no_modules(): void
    {
        $this->shop->storage->setMeta('project_root', '/no/such/checkout');

        [$status, $body] = $this->ui();

        self::assertSame(200, $status);
        self::assertSame('project-root-missing', $body['status']);
        self::assertStringContainsString('/no/such/checkout', JsonRead::text($body['message']));
        self::assertSame([], $body['modules']);
    }

    /** @return array{int, array<string, mixed>} */
    private function ui(string $module = '', string $id = ''): array
    {
        return $this->ask($this->readerOf($this->shop->storage), array_filter(['view' => 'ui', 'module' => $module, 'id' => $id], static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param array<string, string> $query
     * @return array{int, array<string, mixed>}
     */
    private function ask(TraceGraphReader $reader, array $query): array
    {
        $payload = new ObservatoryGraphPayload();
        // As the hydrator fills it: through the setters.
        foreach ($query as $name => $value) {
            $payload->{'set' . ucfirst($name)}($value);
        }
        $handler = new ObservatoryGraphHandler();
        (new \ReflectionProperty($handler, 'gate'))->setValue($handler, new ObservatoryPanelGate());
        (new \ReflectionProperty($handler, 'graph'))->setValue($handler, $reader);

        $response = $handler->handle($payload, new ResourceResponse());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return [$response->getStatusCode(), $body];
    }

    /** A reader that already has this graph open. */
    private function readerOf(GraphStorage $storage): TraceGraphReader
    {
        $reader = (new \ReflectionClass(TraceGraphReader::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($reader, 'storage'))->setValue($reader, $storage);

        return $reader;
    }
}
