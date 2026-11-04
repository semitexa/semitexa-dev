<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Console\Command\DevBenchCommand;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** dev:bench against a real server on a free port: a valid run succeeds and writes the v1 artifact. */
final class DevBenchRunTest extends TestCase
{
    #[Test]
    public function a_valid_run_writes_one_measurement_per_route_and_level(): void
    {
        $exit = null;
        $display = '';
        $hosts = [];
        Coroutine\run(function () use (&$exit, &$display, &$hosts): void {
            $server = new Server('127.0.0.1', 0);
            // '/' matches every path: /a and /b included.
            $server->handle('/', static function (Request $request, Response $response) use (&$hosts): void {
                $hosts[$request->header['host'] ?? '(none)'] = true;
                $response->end('ok');
            });
            Coroutine::create(static fn () => $server->start());
            $tester = new CommandTester(new DevBenchCommand());
            $exit = $tester->execute([
                '--route' => ['/a', '/b'],
                '--concurrency' => '1,2',
                '--duration' => '0.1',
                '--warmup' => '0',
                '--repeat' => '2',
                '--base-url' => 'http://127.0.0.1:' . $server->port,
                '--host' => 'shop.test',
            ]);
            $display = $tester->getDisplay();
            $server->shutdown();
        });

        self::assertSame(Command::SUCCESS, $exit, $display);
        $artifact = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('semitexa-dev.bench/v1', $artifact['artifact']);
        self::assertSame('shop.test', $artifact['server']['host']);
        self::assertSame(['shop.test'], array_keys($hosts), 'the Host header the server received, not only the option echoed');
        self::assertSame([1, 2], $artifact['method']['concurrency']);
        self::assertSame(['GET /a', 'GET /a', 'GET /b', 'GET /b'], array_column($artifact['runs'], 'route'));
        foreach ($artifact['runs'] as $run) {
            self::assertGreaterThan(0, $run['requests']);
            self::assertSame(0, $run['errors']);
            self::assertSame(2, $run['windows']);
            self::assertCount(2, $run['req_per_second_each']);
            self::assertCount(2, $run['load_1m_each']);
            self::assertArrayHasKey('spread_percent', $run);
        }
    }

    #[Test]
    public function the_warm_up_draws_a_new_connection_per_request(): void
    {
        [$display, $warm] = $this->benchCountingRequests('0.05', '0.05', '1');

        self::assertSame(16, json_decode($display, true, 512, JSON_THROW_ON_ERROR)['method']['warm_connections']);
        // A request without keep-alive is its own connection. Not counted by fd: a closed fd is reused.
        self::assertGreaterThanOrEqual(16, $warm, 'the first 16 in parallel, each without keep-alive; a keep-alive warm-up counts 0');
    }

    #[Test]
    public function no_warm_up_asked_for_means_none_at_all(): void
    {
        [$display, $warm] = $this->benchCountingRequests('0.05', '0', '1');

        self::assertSame(0, json_decode($display, true, 512, JSON_THROW_ON_ERROR)['method']['warm_connections']);
        self::assertSame(0, $warm);
    }

    #[Test]
    public function each_level_gets_its_own_warm_up_because_it_opens_its_own_connections(): void
    {
        // Two levels: 2 x 0.3 s of warm-up. Once per route it would span 0.34 s.
        [, , $measuredSeconds] = $this->benchCountingRequests('0.02', '0.3', '1', '1,2');

        self::assertGreaterThan(0.55, $measuredSeconds);
    }

    #[Test]
    public function the_warm_up_runs_before_the_first_window_and_not_before_each(): void
    {
        // Once: 0.3 s of warm-up and 5 x 0.02 s of windows, 0.4 s. Per window it would be 1.6 s.
        [, , $measuredSeconds] = $this->benchCountingRequests('0.02', '0.3', '5');

        self::assertGreaterThan(0.3, $measuredSeconds);
        self::assertLessThan(1.0, $measuredSeconds);
    }

    /** @return array{0: string, 1: int, 2: float} The artifact, the warm-pass requests, and the seconds keep-alive requests spanned. */
    private function benchCountingRequests(string $duration, string $warmup, string $repeat, string $concurrency = '1'): array
    {
        $exit = null;
        $display = '';
        $warm = 0;
        $first = null;
        $last = null;
        Coroutine\run(function () use (&$exit, &$display, &$warm, &$first, &$last, $duration, $warmup, $repeat, $concurrency): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', static function (Request $request, Response $response) use (&$warm, &$first, &$last): void {
                if (strtolower($request->header['connection'] ?? '') === 'keep-alive') {
                    $first ??= hrtime(true);
                    $last = hrtime(true);
                } else {
                    $warm++;
                }
                $response->end('ok');
            });
            Coroutine::create(static fn () => $server->start());
            $tester = new CommandTester(new DevBenchCommand());
            $exit = $tester->execute([
                '--route' => ['/a'],
                '--concurrency' => $concurrency,
                '--duration' => $duration,
                '--warmup' => $warmup,
                '--repeat' => $repeat,
                '--base-url' => 'http://127.0.0.1:' . $server->port,
            ]);
            $display = $tester->getDisplay();
            $server->shutdown();
        });

        self::assertSame(Command::SUCCESS, $exit, $display);

        return [$display, $warm, ($last - $first) / 1e9];
    }

    #[Test]
    public function three_windows_are_measured_unless_told_otherwise(): void
    {
        $exit = null;
        $display = '';
        Coroutine\run(function () use (&$exit, &$display): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', static fn (Request $request, Response $response) => $response->end('ok'));
            Coroutine::create(static fn () => $server->start());
            $tester = new CommandTester(new DevBenchCommand());
            $exit = $tester->execute([
                '--route' => ['/a'],
                '--concurrency' => '1',
                '--duration' => '0.02',
                '--warmup' => '0',
                '--base-url' => 'http://127.0.0.1:' . $server->port,
            ]);
            $display = $tester->getDisplay();
            $server->shutdown();
        });

        self::assertSame(Command::SUCCESS, $exit, $display);
        $artifact = json_decode($display, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(3, $artifact['method']['windows']);
        self::assertSame(3, $artifact['runs'][0]['windows']);
    }

    #[Test]
    public function a_repeat_that_is_no_small_whole_number_is_refused(): void
    {
        foreach (['0', '21', '1.5', 'x', ''] as $repeat) {
            $tester = new CommandTester(new DevBenchCommand());
            $exit = $tester->execute(['--route' => ['/a'], '--repeat' => $repeat, '--base-url' => 'http://127.0.0.1:1']);

            self::assertSame(Command::FAILURE, $exit, "--repeat={$repeat} was accepted");
            self::assertStringContainsString('--repeat', $tester->getDisplay());
        }
    }
}
