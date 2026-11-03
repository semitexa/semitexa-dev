<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Bench\LoadGenerator;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

/**
 * The generator against a real coroutine HTTP server on a free port: it keeps
 * the requested number of connections busy for the window, counts every
 * answer by status, and counts a 5xx as an error but not a 404.
 */
final class LoadGeneratorTest extends TestCase
{
    #[Test]
    public function it_loads_a_server_for_the_window_and_counts_answers_by_status(): void
    {
        $runs = [];
        $seen = 0;
        Coroutine\run(function () use (&$runs, &$seen): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', function (Request $request, Response $response) use (&$seen): void {
                $seen++;
                $response->status(match ($request->server['request_uri']) {
                    '/broken' => 500,
                    '/missing' => 404,
                    default => 200,
                });
                $response->end('ok');
            });
            Coroutine::create(static fn () => $server->start());
            $generator = new LoadGenerator('127.0.0.1', $server->port);
            $runs['ok'] = $generator->run('/ok', 4, 0.3, 0.1);
            $runs['missing'] = $generator->run('/missing', 2, 0.2);
            $runs['broken'] = $generator->run('/broken', 2, 0.2);
            $server->shutdown();
        });

        $ok = $runs['ok'];
        self::assertGreaterThan(20, $ok->requests, 'four connections for 0.3 s answer many requests');
        self::assertSame([200 => $ok->requests], $ok->statuses);
        self::assertSame(0, $ok->errors);
        self::assertGreaterThanOrEqual(0.3, $ok->seconds);
        self::assertGreaterThan($ok->requests + $runs['missing']->requests + $runs['broken']->requests, $seen, 'the warm-up was sent too, and left out of the counts');
        self::assertGreaterThan(0, $runs['missing']->requests);
        self::assertSame([404 => $runs['missing']->requests], $runs['missing']->statuses);
        self::assertSame(0, $runs['missing']->errors, 'a 404 is an answer, not an error');
        self::assertGreaterThan(0, $runs['broken']->requests);
        self::assertSame([500 => $runs['broken']->requests], $runs['broken']->statuses);
        self::assertSame($runs['broken']->requests, $runs['broken']->errors, 'every 500 is an error');
        self::assertGreaterThan(0.0, $ok->latency->p99Ms);
    }

    #[Test]
    public function a_server_that_is_not_there_is_all_errors_not_a_fast_route(): void
    {
        $run = (new LoadGenerator('127.0.0.1', 1, 0.2))->run('/', 1, 0.2);

        self::assertGreaterThan(0, $run->requests);
        self::assertSame($run->requests, $run->errors);
        self::assertSame([0 => $run->requests], $run->statuses);
    }

    #[Test]
    public function past_the_sample_cap_the_count_stays_exact_and_the_latencies_a_sample(): void
    {
        $run = null;
        Coroutine\run(function () use (&$run): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', static fn (Request $request, Response $response) => $response->end('ok'));
            Coroutine::create(static fn () => $server->start());
            $run = (new LoadGenerator('127.0.0.1', $server->port, 10.0, 10))->run('/', 2, 0.2);
            $server->shutdown();
        });

        self::assertGreaterThan(10, $run->requests, 'every answer is counted');
        self::assertSame(10, $run->latency->count, 'ten latencies are kept');
    }

    #[Test]
    public function a_duration_that_is_no_finite_number_is_refused(): void
    {
        foreach ([[NAN, 0.0], [INF, 0.0], [1.0, NAN], [1.0, -1.0]] as [$seconds, $warmup]) {
            try {
                (new LoadGenerator('127.0.0.1', 1))->run('/', 1, $seconds, $warmup);
                self::fail("{$seconds}/{$warmup} was accepted");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function a_connection_swoole_refuses_to_start_is_named_and_the_others_are_released(): void
    {
        $error = null;
        Coroutine::set(['max_coroutine' => 8]);
        try {
            Coroutine\run(function () use (&$error): void {
                try {
                    (new LoadGenerator('127.0.0.1', 1, 0.2))->run('/', 32, 0.2);
                } catch (\RuntimeException $e) {
                    $error = $e->getMessage();
                }
            });
        } finally {
            Coroutine::set(['max_coroutine' => 100000]);
        }

        self::assertIsString($error, 'the run stops with an error, not a hang');
        self::assertStringContainsString('of 32 could not start', $error);
    }
}
