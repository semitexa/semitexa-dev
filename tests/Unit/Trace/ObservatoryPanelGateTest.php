<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
use Semitexa\Core\Request;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * The gate is what makes monitor mode shippable to a production box: the
 * journal may run there, but the panel answers only to the operator's token or
 * a direct loopback tunnel — never to the public side of the reverse proxy.
 */
final class ObservatoryPanelGateTest extends TestCase
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
        // Restore what the process started with; a variable that was unset stays unset.
        foreach ($this->envSnapshot as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = [], string $remoteAddr = '127.0.0.1'): void
    {
        CurrentRequestStore::set(new Request(
            method: 'GET',
            uri: '/__observatory',
            headers: $headers,
            query: [],
            post: [],
            server: ['remote_addr' => $remoteAddr],
            cookies: [],
        ));
    }

    #[Test]
    public function dev_is_open_and_off_is_closed(): void
    {
        putenv('APP_ENV=dev');
        self::assertTrue((new ObservatoryPanelGate())->allows(), 'dev panel stays open, as before the gate');

        putenv('APP_ENV=prod');
        $this->request();
        self::assertFalse((new ObservatoryPanelGate())->allows(), 'without monitor mode there is nothing to show');
    }

    #[Test]
    public function a_configured_token_decides_exclusively(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        $gate = new ObservatoryPanelGate();

        $this->request(['X-Observatory-Token' => 's3cret'], '203.0.113.9');
        self::assertTrue($gate->allows(), 'the right token admits from anywhere');

        $this->request(['X-Observatory-Token' => 'wrong'], '127.0.0.1');
        self::assertFalse($gate->allows(), 'a wrong token must not fall through to the loopback rule');

        $this->request([], '127.0.0.1');
        self::assertFalse($gate->allows(), 'a missing token must not fall through to the loopback rule');
    }

    #[Test]
    public function the_monitor_token_does_not_open_the_explorer(): void
    {
        // Monitor is the production mode: the token unlocks the measurement
        // panel, not the route map, OpenAPI document and sandbox.
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        $gate = new ObservatoryPanelGate();

        $this->request(['X-Observatory-Token' => 's3cret'], '203.0.113.9');
        self::assertTrue($gate->allows());
        self::assertFalse($gate->allowsDevTools());

        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_MODE');
        self::assertTrue($gate->allowsDevTools(), 'dev mode keeps the explorer');
    }

    #[Test]
    public function without_a_token_only_a_direct_loopback_peer_walks_in(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        $gate = new ObservatoryPanelGate();

        $this->request([], '127.0.0.1');
        self::assertTrue($gate->allows(), 'an SSH tunnel straight to the app port is the operator');

        $this->request(['X-Forwarded-For' => '203.0.113.9'], '127.0.0.1');
        self::assertFalse($gate->allows(), 'a loopback peer with forwarding headers is the reverse proxy');

        $this->request([], '203.0.113.9');
        self::assertFalse($gate->allows(), 'a remote peer without the token stays out');
    }

    #[Test]
    public function no_request_in_scope_means_no(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        CurrentRequestStore::clear();

        self::assertFalse((new ObservatoryPanelGate())->allows());
    }
}
