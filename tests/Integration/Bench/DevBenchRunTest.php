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
        }
    }
}
