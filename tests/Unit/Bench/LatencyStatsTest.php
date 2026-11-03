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
        self::assertSame(0, LatencyStats::of([])->count);
    }
}
