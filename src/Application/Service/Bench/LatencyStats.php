<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Bench;

/**
 * Latency percentiles of one load run, by nearest rank: p99 is a latency a
 * request actually had, never an interpolation between two (tk-fast-bench-harness).
 */
final readonly class LatencyStats
{
    private function __construct(
        public int $count,
        public float $p50Ms,
        public float $p95Ms,
        public float $p99Ms,
        public float $maxMs,
        public float $meanMs,
    ) {}

    /** @param list<float> $latenciesMs */
    public static function of(array $latenciesMs): self
    {
        if ($latenciesMs === []) {
            return new self(0, 0.0, 0.0, 0.0, 0.0, 0.0);
        }
        sort($latenciesMs);
        $n = count($latenciesMs);
        $rank = static fn (float $p): float => $latenciesMs[max(0, (int) ceil($p * $n) - 1)];

        return new self($n, $rank(0.50), $rank(0.95), $rank(0.99), $latenciesMs[$n - 1], array_sum($latenciesMs) / $n);
    }

    /** @return array{count: int, p50_ms: float, p95_ms: float, p99_ms: float, max_ms: float, mean_ms: float} */
    public function toArray(): array
    {
        $r = static fn (float $v): float => round($v, 3);

        return ['count' => $this->count, 'p50_ms' => $r($this->p50Ms), 'p95_ms' => $r($this->p95Ms), 'p99_ms' => $r($this->p99Ms), 'max_ms' => $r($this->maxMs), 'mean_ms' => $r($this->meanMs)];
    }
}
