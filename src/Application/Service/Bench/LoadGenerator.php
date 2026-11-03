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
    /**
     * Latencies kept per window. A fast route at high concurrency answers
     * millions of requests in 300 s; past this many the window keeps a uniform
     * sample (reservoir sampling), so the percentiles stay unbiased and the
     * memory stays at about 16 MB. The request count is always exact.
     */
    public const MAX_SAMPLES = 1_000_000;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutS = 10.0,
        private readonly int $maxSamples = self::MAX_SAMPLES,
    ) {}

    /** @param array<string, string> $headers */
    public function run(string $path, int $concurrency, float $seconds, float $warmupS = 0.0, array $headers = []): LoadRun
    {
        // NAN and INF pass a plain comparison: the window would end before it starts.
        if ($concurrency < 1 || !is_finite($seconds) || $seconds <= 0 || !is_finite($warmupS) || $warmupS < 0) {
            throw new \InvalidArgumentException('a load run needs a concurrency of 1 or more, a finite duration above 0 and a finite warm-up of 0 or more');
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
        $answered = 0;
        $statuses = [];
        $errors = 0;
        $deadline = 0;
        $go = new Coroutine\Channel($concurrency);
        $done = new Coroutine\Channel($concurrency);
        $started = 0;
        for ($i = 0; $i < $concurrency; $i++) {
            // @: past max_coroutine Swoole warns and returns false; the error below names it.
            $cid = @Coroutine::create(function () use ($path, $headers, &$deadline, $go, $done, &$latencies, &$answered, &$statuses, &$errors): void {
                $client = new Client($this->host, $this->port);
                $client->set(['timeout' => $this->timeoutS, 'keep_alive' => true]);
                // The caller's headers win: a tenant or locale is chosen by Host.
                $client->setHeaders($headers + ['Host' => $this->host, 'Accept' => 'text/html']);
                // Every connection starts the window together: one created last
                // would otherwise get less of it.
                $go->pop();
                while (hrtime(true) < $deadline) {
                    $sent = hrtime(true);
                    $client->get($path);
                    $latency = (hrtime(true) - $sent) / 1e6;
                    $answered++;
                    if ($answered <= $this->maxSamples) {
                        $latencies[] = $latency;
                    } elseif (($slot = random_int(0, $answered - 1)) < $this->maxSamples) {
                        $latencies[$slot] = $latency;
                    }
                    $status = $client->statusCode > 0 ? $client->statusCode : 0;
                    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
                    if ($status === 0 || $status >= 500) {
                        $errors++;
                        // A dropped connection is reopened, not left to fail every request.
                        if ($status === 0) {
                            $client->close();
                            // A refused connection is retried, not spun on.
                            Coroutine::sleep(0.01);
                        }
                    }
                }
                $client->close();
                $done->push(true);
            });
            if ($cid === false) {
                // The coroutine limit (max_coroutine): release the ones that
                // started — the deadline is still 0, so each closes at once.
                for ($j = 0; $j < $started; $j++) {
                    $go->push(true);
                }
                for ($j = 0; $j < $started; $j++) {
                    $done->pop();
                }
                throw new \RuntimeException(sprintf('connection %d of %d could not start: Swoole refused the coroutine (max_coroutine); lower --concurrency', $i + 1, $concurrency));
            }
            $started++;
        }
        $start = hrtime(true);
        $deadline = $start + (int) ($seconds * 1e9);
        for ($i = 0; $i < $concurrency; $i++) {
            $go->push(true);
        }
        for ($i = 0; $i < $concurrency; $i++) {
            $done->pop();
        }
        $elapsed = (hrtime(true) - $start) / 1e9;

        return new LoadRun('GET ' . $path, $concurrency, $elapsed, $answered, $errors, $statuses, LatencyStats::of($latencies));
    }
}
