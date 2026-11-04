<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Bench;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Bench\LatencyStats;
use Semitexa\Dev\Application\Service\Bench\LoadRun;
use Semitexa\Dev\Application\Service\Bench\LoadSeries;

final class LoadSeriesTest extends TestCase
{
    #[Test]
    public function the_median_is_a_real_window_so_its_latency_belongs_to_its_rate(): void
    {
        $slow = $this->window(100, 90.0);
        $middle = $this->window(150, 50.0);
        $fast = $this->window(200, 20.0);

        $this->assertSame($middle, (new LoadSeries([$fast, $slow, $middle], []))->median());
    }

    #[Test]
    public function an_even_count_quotes_the_lower_middle_rather_than_flattering_the_run(): void
    {
        $series = new LoadSeries([$this->window(100, 1.0), $this->window(200, 1.0)], []);

        $this->assertSame(100, $series->median()->requests);
    }

    #[Test]
    public function the_spread_is_the_range_over_the_median(): void
    {
        $series = new LoadSeries([$this->window(100, 1.0), $this->window(200, 1.0), $this->window(150, 1.0)], []);

        $this->assertEqualsWithDelta(66.7, $series->spreadPercent(), 0.1);
    }

    #[Test]
    public function one_window_has_no_spread_rather_than_a_perfect_one(): void
    {
        $series = new LoadSeries([$this->window(100, 1.0)], []);

        $this->assertNull($series->spreadPercent());
        $this->assertNull($series->toArray()['spread_percent']);
    }

    #[Test]
    public function a_series_with_no_answers_has_no_spread_rather_than_a_division_by_zero(): void
    {
        $this->assertSame(0.0, (new LoadSeries([$this->window(0, 1.0), $this->window(0, 1.0)], []))->spreadPercent());
    }

    #[Test]
    public function the_artifact_keeps_the_median_windows_fields_and_adds_every_window_in_order(): void
    {
        $array = (new LoadSeries([$this->window(200, 1.0), $this->window(100, 1.0), $this->window(150, 1.0)], [2.5, 3.0, null]))->toArray();

        $this->assertSame(150, $array['requests']);
        $this->assertSame(150.0, $array['req_per_second']);
        $this->assertSame(3, $array['windows']);
        $this->assertSame([200.0, 100.0, 150.0], $array['req_per_second_each']);
        $this->assertSame([2.5, 3.0, null], $array['load_1m_each']);
    }

    #[Test]
    public function errors_in_a_window_that_is_not_the_median_are_still_reported(): void
    {
        $clean = $this->window(150, 1.0);
        $broken = new LoadRun('GET /', 1, 1.0, 400, 400, [500 => 400], LatencyStats::of([1.0]));

        $array = (new LoadSeries([$clean, $broken, $this->window(100, 1.0)], []))->toArray();

        $this->assertSame(0, $array['errors'], 'the median window alone');
        $this->assertSame([0, 400, 0], $array['errors_each']);
        $this->assertSame([[200 => 150], [500 => 400], [200 => 100]], $array['statuses_each']);
    }

    #[Test]
    public function equal_rates_with_different_tails_are_visible(): void
    {
        $array = (new LoadSeries([$this->window(100, 5.0), $this->window(100, 500.0)], []))->toArray();

        $this->assertSame(0.0, $array['spread_percent']);
        $this->assertSame([5.0, 500.0], $array['p99_ms_each']);
    }

    private function window(int $requests, float $latencyMs): LoadRun
    {
        return new LoadRun('GET /', 1, 1.0, $requests, 0, [200 => $requests], LatencyStats::of([$latencyMs]));
    }
}
