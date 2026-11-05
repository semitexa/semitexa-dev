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
    public function connections_open_one_at_a_time_before_the_window(): void
    {
        $firstSeen = [];
        $run = null;
        Coroutine\run(function () use (&$firstSeen, &$run): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', function (Request $request, Response $response) use (&$firstSeen): void {
                $firstSeen[$request->fd] ??= microtime(true);
                $response->end('ok');
            });
            Coroutine::create(static fn () => $server->start());
            $run = (new LoadGenerator('127.0.0.1', $server->port))->run('/', 4, 0.2);
            $server->shutdown();
        });

        self::assertCount(4, $firstSeen, 'keep-alive: one connection per client, for the whole window');
        $times = array_values($firstSeen);
        sort($times);
        for ($i = 1; $i < 4; $i++) {
            // Sleep can only run late, so the gap is a floor; a little under it is timer rounding.
            self::assertGreaterThan(LoadGenerator::CONNECT_GAP_S * 0.8, $times[$i] - $times[$i - 1], 'opened apart, not in one burst');
        }
        self::assertNotNull($run);
        self::assertGreaterThanOrEqual(0.2, $run->seconds, 'the opening is not taken from the window');
    }

    #[Test]
    public function a_slow_first_answer_does_not_eat_the_window(): void
    {
        $run = null;
        Coroutine\run(function () use (&$run): void {
            $first = true;
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', function (Request $request, Response $response) use (&$first): void {
                if ($first) {
                    $first = false;
                    Coroutine::sleep(0.4);
                }
                $response->end('ok');
            });
            Coroutine::create(static fn () => $server->start());
            $run = (new LoadGenerator('127.0.0.1', $server->port))->run('/', 1, 0.2);
            $server->shutdown();
        });

        self::assertNotNull($run);
        self::assertGreaterThan(0, $run->requests, 'the window opens after the slow answer, not during it');
    }

    #[Test]
    public function the_connect_gap_shrinks_to_keep_the_ramp_short(): void
    {
        self::assertSame(LoadGenerator::CONNECT_GAP_S, LoadGenerator::connectGapFor(1));
        self::assertSame(LoadGenerator::CONNECT_GAP_S, LoadGenerator::connectGapFor(64));
        self::assertEqualsWithDelta(LoadGenerator::CONNECT_RAMP_MAX_S, LoadGenerator::connectGapFor(500) * 500, 1e-9);
        self::assertSame(LoadGenerator::CONNECT_GAP_MIN_S, LoadGenerator::connectGapFor(1024), 'never so short that it stops spacing them');
    }

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
    public function a_request_limit_ends_the_window_early_and_counts_what_was_sent(): void
    {
        $run = null;
        Coroutine\run(function () use (&$run): void {
            $server = new Server('127.0.0.1', 0);
            $server->handle('/', static fn (Request $request, Response $response) => $response->end('ok'));
            Coroutine::create(static fn () => $server->start());
            $run = (new LoadGenerator('127.0.0.1', $server->port, maxRequests: 50))->run('/', 2, 30.0);
            $server->shutdown();
        });

        self::assertGreaterThanOrEqual(50, $run->requests);
        self::assertLessThanOrEqual(52, $run->requests, 'the two in flight may each finish');
        self::assertLessThan(10.0, $run->seconds, 'it stopped at the limit, not at the 30 s deadline');
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
    public function connections_past_the_coroutine_limit_are_refused_before_any_starts(): void
    {
        $error = null;
        $running = null;
        $this->underCoroutineOptions(['max_coroutine' => 8], function () use (&$error, &$running): void {
            try {
                (new LoadGenerator('127.0.0.1', 1, 0.2))->run('/', 32, 0.2);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
                $running = Coroutine::stats()['coroutine_num'];
            }
        });

        self::assertIsString($error, 'the run stops with an error, not a hang or a segfault');
        self::assertStringContainsString('32 connections could not start', $error);
        self::assertStringContainsString('max_coroutine 8', $error);
        self::assertSame(1, $running, 'none of the connections was started and then abandoned');
    }

    #[Test]
    public function the_last_free_coroutine_can_be_used_and_the_next_cannot(): void
    {
        $fits = null;
        $error = null;
        $this->underCoroutineOptions(['max_coroutine' => 8], function () use (&$fits, &$error): void {
            $generator = new LoadGenerator('127.0.0.1', 1, 0.05);
            $fits = $generator->run('/', 7, 0.05);
            try {
                $generator->run('/', 8, 0.05);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        });

        self::assertGreaterThan(0, $fits?->requests, 'the seven connections really ran');
        self::assertIsString($error);
        self::assertStringContainsString('8 connections could not start', $error);
        // 6 when the last connection of the run before has pushed its result and not yet ended.
        self::assertMatchesRegularExpression('/room for [67] more/', $error);
    }

    #[Test]
    public function a_limit_of_zero_means_the_default_as_it_does_to_swoole(): void
    {
        $run = null;
        $this->underCoroutineOptions(['max_coroutine' => 0], function () use (&$run): void {
            $run = (new LoadGenerator('127.0.0.1', 1, 0.05))->run('/', 2, 0.05);
        });

        self::assertGreaterThan(0, $run?->requests);
    }

    #[Test]
    public function the_old_spelling_of_the_limit_counts_even_beside_a_stale_new_one(): void
    {
        $error = null;
        Coroutine::set(['max_coroutine' => 100000]);
        $this->underCoroutineOptions(['max_coro_num' => 8], function () use (&$error): void {
            try {
                (new LoadGenerator('127.0.0.1', 1, 0.05))->run('/', 32, 0.05);
            } catch (\RuntimeException $e) {
                $error = $e->getMessage();
            }
        });

        self::assertIsString($error);
        self::assertStringContainsString('max_coroutine 8', $error);
    }

    /**
     * Coroutine options are process-wide: whatever a test sets is put back, under
     * both spellings, or it leaks into whichever test runs next.
     *
     * @param array<string, int> $options
     */
    private function underCoroutineOptions(array $options, callable $work): void
    {
        $before = Coroutine::getOptions() ?? [];
        Coroutine::set($options);
        try {
            Coroutine\run($work);
        } finally {
            $limit = (int) ($before['max_coroutine'] ?? 0);
            $limit = $limit > 0 ? $limit : 100000;
            Coroutine::set(['max_coroutine' => $limit, 'max_coro_num' => (int) ($before['max_coro_num'] ?? $limit)]);
        }
    }
}
