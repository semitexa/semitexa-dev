<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Everything one traced request is accumulating.
 *
 * A separate object rather than fields on the tracer because the tracer is a
 * single instance shared by the whole worker: with the state on the service, a
 * concurrent untraced request calling begin('request') would set recording to
 * false and clear the events of the traced request running beside it. The buffer
 * lives in the coroutine context instead, so each request has its own.
 */
final class TraceBuffer
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public int $depth = 0;

    /** @var array<string, float> open span name => start time in nanoseconds */
    public array $open = [];

    public bool $failed = false;

    public function __construct(
        public readonly float $startedAt,
        public readonly int $rootCid,
    ) {
    }

    public function sinceStartMs(): float
    {
        return round(((float) hrtime(true) - $this->startedAt) / 1_000_000, 3);
    }
}
