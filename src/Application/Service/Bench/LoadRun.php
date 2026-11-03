<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Bench;

/** What one route did at one concurrency for one measured window. */
final readonly class LoadRun
{
    /** @param array<int, int> $statuses HTTP status => count (0 for a request that got no response) */
    public function __construct(
        public string $route,
        public int $concurrency,
        public float $seconds,
        public int $requests,
        public int $errors,
        public array $statuses,
        public LatencyStats $latency,
    ) {}

    public function requestsPerSecond(): float
    {
        return $this->seconds > 0 ? $this->requests / $this->seconds : 0.0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $statuses = $this->statuses;
        ksort($statuses);

        return [
            'route' => $this->route,
            'concurrency' => $this->concurrency,
            'seconds' => round($this->seconds, 3),
            'requests' => $this->requests,
            'errors' => $this->errors,
            'req_per_second' => round($this->requestsPerSecond(), 1),
            'statuses' => $statuses,
            'latency' => $this->latency->toArray(),
        ];
    }
}
