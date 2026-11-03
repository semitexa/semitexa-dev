<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Bench;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;

/**
 * Closed-loop load on one route (tk-fast-bench-harness): N coroutines, each
 * with its own keep-alive connection, send the next request as soon as the
 * last one answers, first for a warm-up the numbers leave out, then for a
 * fixed measured window. Built on Swoole's coroutine HTTP client because the
 * image ships Swoole and no load tool (no wrk, hey or k6), and a new
 * dependency is the operator's call.
 *
 * A request that gets no response, or a 5xx, is an error. A 3xx or 4xx is an
 * answer the route chose and is counted by status, not as an error.
 */
final class LoadGenerator
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutS = 10.0,
    ) {}

    /** @param array<string, string> $headers */
    public function run(string $path, int $concurrency, float $seconds, float $warmupS = 0.0, array $headers = []): LoadRun
    {
        if ($concurrency < 1 || $seconds <= 0) {
            throw new \InvalidArgumentException('a load run needs a concurrency of 1 or more and a duration above 0');
        }
        $result = null;
        $work = function () use ($path, $concurrency, $seconds, $warmupS, $headers, &$result): void {
            if ($warmupS > 0) {
                $this->window($path, $concurrency, $warmupS, $headers);
            }
            $result = $this->window($path, $concurrency, $seconds, $headers);
        };
        Coroutine::getCid() > 0 ? $work() : Coroutine\run($work);
        if (!$result instanceof LoadRun) {
            throw new \RuntimeException('the load run did not finish');
        }

        return $result;
    }

    /** @param array<string, string> $headers */
    private function window(string $path, int $concurrency, float $seconds, array $headers): LoadRun
    {
        $latencies = [];
        $statuses = [];
        $errors = 0;
        $start = hrtime(true);
        $deadline = $start + (int) ($seconds * 1e9);
        $done = new Coroutine\Channel($concurrency);
        for ($i = 0; $i < $concurrency; $i++) {
            Coroutine::create(function () use ($path, $headers, $deadline, $done, &$latencies, &$statuses, &$errors): void {
                $client = new Client($this->host, $this->port);
                $client->set(['timeout' => $this->timeoutS, 'keep_alive' => true]);
                // The caller's headers win: a tenant or locale is chosen by Host.
                $client->setHeaders($headers + ['Host' => $this->host, 'Accept' => 'text/html']);
                while (hrtime(true) < $deadline) {
                    $sent = hrtime(true);
                    $client->get($path);
                    $latencies[] = (hrtime(true) - $sent) / 1e6;
                    $status = $client->statusCode > 0 ? $client->statusCode : 0;
                    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                    if ($status === 0 || $status >= 500) {
                        $errors++;
                        // A dropped connection is reopened, not left to fail every request.
                        if ($status === 0) {
                            $client->close();
                        }
                    }
                }
                $client->close();
                $done->push(true);
            });
        }
        for ($i = 0; $i < $concurrency; $i++) {
            $done->pop();
        }
        $elapsed = (hrtime(true) - $start) / 1e9;

        return new LoadRun('GET ' . $path, $concurrency, $elapsed, count($latencies), $errors, $statuses, LatencyStats::of($latencies));
    }
}
