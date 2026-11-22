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
            // Each test starts with all three empty and sets what it needs.
            // Empty, not unset: an unset key falls back to the project's .env
            // (Environment::getEnvValue), so a developer's own token or mode
            // would change the tokenless answers (review semitexa-dev#307).
            putenv($key . '=');
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
    private function request(array $headers = [], string $remoteAddr = '127.0.0.1', string $method = 'GET'): void
    {
        CurrentRequestStore::set(new Request(
            method: $method,
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
        putenv('SEMITEXA_OBSERVATORY_MODE=');
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

    #[Test]
    public function dev_is_open_to_this_machine_only(): void
    {
        putenv('APP_ENV=dev');
        $gate = new ObservatoryPanelGate();

        $this->request(['Host' => 'dev.semitexa.test', 'X-Real-Ip' => '172.23.0.1'], '127.0.0.1');
        self::assertTrue($gate->allows(), 'this machine, through the local router');
        self::assertTrue($gate->allowsDevTools());

        // Measured 2026-11-03: the laptop's Wi-Fi address served the graph and
        // the Explorer sandbox to anyone on the network.
        $this->request(['Host' => '192.168.100.2:9507'], '192.168.100.7');
        self::assertFalse($gate->allows(), 'another machine on the network stays out');
        self::assertFalse($gate->allowsDevTools());

        $this->request(['Host' => 'rebind.evil.example:9507'], '127.0.0.1');
        self::assertFalse($gate->allows(), 'a public name rebound to loopback stays out');

        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        $this->request(['Host' => '192.168.100.2:9507', 'X-Observatory-Token' => 's3cret'], '192.168.100.7');
        self::assertTrue($gate->allows(), 'a dev stack used from elsewhere on purpose carries the token');
        self::assertTrue($gate->allowsDevTools(), 'the same token opens the dev tools');
        $this->request(['Host' => '192.168.100.2:9507', 'X-Observatory-Token' => 'wrong'], '192.168.100.7');
        self::assertFalse($gate->allows());
    }

    /**
     * Measured 2026-11-22: a fetch() from https://evil.example to
     * localhost:9507/__observatory/logs answered 200 with
     * `Access-Control-Allow-Origin: *`. ThisMachine does not help: the browser
     * that runs the foreign page IS this machine.
     */
    #[Test]
    public function dev_refuses_a_page_on_another_site(): void
    {
        putenv('APP_ENV=dev');
        $gate = new ObservatoryPanelGate();

        $this->request(['Host' => 'localhost:9507', 'Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']);
        self::assertFalse($gate->allows(), 'a fetch from another site reads nothing');
        self::assertFalse($gate->allowsDevTools());

        $this->request(['Host' => 'localhost:9507', 'Sec-Fetch-Site' => 'same-site', 'Sec-Fetch-Mode' => 'no-cors', 'Sec-Fetch-Dest' => 'script']);
        self::assertFalse($gate->allows(), 'another port of localhost is another origin');

        // A browser without fetch metadata still names the caller in Origin.
        $this->request(['Host' => 'localhost:9507', 'Origin' => 'https://evil.example']);
        self::assertFalse($gate->allows(), 'a foreign Origin alone is enough');
        $this->request(['Host' => 'localhost:9507', 'Origin' => 'null']);
        self::assertFalse($gate->allows(), 'an opaque origin (sandboxed frame, file://) is foreign');

        // The stage switch is a POST and #[CsrfExempt]: a form on another site
        // submits it as a navigation, which is only harmless for a GET.
        $this->request(['Host' => 'localhost:9507', 'Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document'], '127.0.0.1', 'POST');
        self::assertFalse($gate->allows(), 'a cross-site form POST is refused');
    }

    #[Test]
    public function dev_keeps_the_developers_own_use(): void
    {
        putenv('APP_ENV=dev');
        $gate = new ObservatoryPanelGate();

        $this->request(['Host' => 'localhost:9507', 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty']);
        self::assertTrue($gate->allows(), 'the panel polling its own feed');

        $this->request(['Host' => 'localhost:9507', 'Origin' => 'http://localhost:9507', 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'], '127.0.0.1', 'POST');
        self::assertTrue($gate->allows(), 'the panel switching stage mode');

        $this->request(['Host' => 'localhost:9507', 'Sec-Fetch-Site' => 'none', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']);
        self::assertTrue($gate->allows(), 'the address typed into the bar');

        $this->request(['Host' => 'localhost:9507', 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'navigate', 'Sec-Fetch-Dest' => 'document']);
        self::assertTrue($gate->allows(), 'a link followed from docs or chat opens the page; the other site cannot read it');

        // No fetch metadata and no Origin: curl, ai:observe, an old browser on
        // its own page. Same answer as allowsDevTools() and the Workbench gate.
        $this->request(['Host' => 'localhost:9507']);
        self::assertTrue($gate->allows(), 'curl from this machine');
    }

    #[Test]
    public function monitor_refuses_another_site_through_the_tunnel_and_with_the_token(): void
    {
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');
        $gate = new ObservatoryPanelGate();
        $foreign = ['Host' => 'localhost:9507', 'Origin' => 'https://evil.example', 'Sec-Fetch-Site' => 'cross-site', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'];

        // The operator's browser at the far end of `ssh -L`: the tunnel makes
        // every tab in it a direct loopback peer.
        $this->request($foreign, '127.0.0.1');
        self::assertFalse($gate->allows(), 'a foreign page must not ride the tunnel');

        $this->request(['Host' => 'localhost:9507', 'Sec-Fetch-Site' => 'same-origin', 'Sec-Fetch-Mode' => 'cors', 'Sec-Fetch-Dest' => 'empty'], '127.0.0.1');
        self::assertTrue($gate->allows(), 'the panel itself, through the tunnel');

        putenv('SEMITEXA_OBSERVATORY_TOKEN=s3cret');
        $this->request($foreign + ['X-Observatory-Token' => 's3cret'], '203.0.113.9');
        self::assertFalse($gate->allows(), 'same rule as the dev tools: the token does not make a foreign page a reader');
        $this->request(['Host' => 'ops.example', 'Sec-Fetch-Site' => 'same-origin', 'X-Observatory-Token' => 's3cret'], '203.0.113.9');
        self::assertTrue($gate->allows());
    }
}
