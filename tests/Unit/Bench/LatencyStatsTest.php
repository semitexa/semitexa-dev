<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Bench\LatencyStats;

/** Percentiles by nearest rank: a latency some request actually had. */
final class LatencyStatsTest extends TestCase
{
    #[Test]
    public function percentiles_are_latencies_that_occurred(): void
    {
        $stats = LatencyStats::of(array_map('floatval', array_reverse(range(1, 100))));

        self::assertSame(['count' => 100, 'p50_ms' => 50.0, 'p95_ms' => 95.0, 'p99_ms' => 99.0, 'max_ms' => 100.0, 'mean_ms' => 50.5], $stats->toArray());
        self::assertSame(7.0, LatencyStats::of([7.0])->p99Ms, 'one request is every percentile');
        self::assertSame(['count' => 0, 'p50_ms' => 0.0, 'p95_ms' => 0.0, 'p99_ms' => 0.0, 'max_ms' => 0.0, 'mean_ms' => 0.0], LatencyStats::of([])->toArray());
    }

    #[Test]
    public function a_run_reports_its_rate_and_every_field(): void
    {
        $run = new \Semitexa\Dev\Application\Service\Bench\LoadRun('GET /x', 4, 2.0, 100, 3, [500 => 3, 200 => 97], LatencyStats::of([1.0, 2.0]));

        self::assertSame(['route', 'concurrency', 'seconds', 'requests', 'errors', 'req_per_second', 'statuses', 'latency'], array_keys($run->toArray()));
        self::assertSame(50.0, $run->toArray()['req_per_second']);
        self::assertSame([200 => 97, 500 => 3], $run->toArray()['statuses'], 'statuses in order');
    }
}
