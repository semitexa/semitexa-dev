<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Bench;

/**
 * One route at one concurrency, measured several times.
 *
 * A single window is one draw: on a shared machine identical code gave 97 to
 * 214 req/s within a minute. The median window is the number to quote, and the
 * spread and the load say how far to trust it. The spread is of the rate only:
 * compare `p99_ms_each` for the latency.
 */
final readonly class LoadSeries
{
    /**
     * @param non-empty-list<LoadRun> $windows In the order they ran.
     * @param list<float|null>        $loads   The machine's 1-minute load as each window started.
     */
    public function __construct(
        public array $windows,
        public array $loads,
    ) {}

    public function median(): LoadRun
    {
        $sorted = $this->windows;
        usort($sorted, static fn (LoadRun $a, LoadRun $b): int => $a->requestsPerSecond() <=> $b->requestsPerSecond());

        // A real window, not an average of two: its latencies belong to its req/s.
        return $sorted[intdiv(count($sorted) - 1, 2)];
    }

    /** How far apart the windows were, as a percentage of the median; null for one window, which has no spread to speak of. */
    public function spreadPercent(): ?float
    {
        if (count($this->windows) < 2) {
            return null;
        }

        $rates = array_map(static fn (LoadRun $run): float => $run->requestsPerSecond(), $this->windows);
        $median = $this->median()->requestsPerSecond();

        return $median > 0 ? (max($rates) - min($rates)) / $median * 100 : 0.0;
    }

    /**
     * The median window, and what every window said. Its own fields answer for
     * that window alone: errors in the others are in `errors_each`, or a run whose
     * second window was all 500s would read as clean.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $spread = $this->spreadPercent();

        return $this->median()->toArray() + [
            'windows' => count($this->windows),
            'req_per_second_each' => array_map(static fn (LoadRun $run): float => round($run->requestsPerSecond(), 1), $this->windows),
            'p99_ms_each' => array_map(static fn (LoadRun $run): float => $run->latency->toArray()['p99_ms'], $this->windows),
            'errors_each' => array_map(static fn (LoadRun $run): int => $run->errors, $this->windows),
            'statuses_each' => array_map(static fn (LoadRun $run): array => $run->toArray()['statuses'], $this->windows),
            'spread_percent' => $spread === null ? null : round($spread, 1),
            'load_1m_each' => $this->loads,
        ];
    }
}
