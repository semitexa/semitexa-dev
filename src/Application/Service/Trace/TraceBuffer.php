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
    /**
     * An SSE connection can stay open for hours and emit a frame per tick, so the
     * buffer is capped. Past the cap it stops recording and says so, rather than
     * growing until the worker runs out of memory - a diagnostic tool that can
     * exhaust the process it observes is worse than one that stops early.
     */
    public const MAX_EVENTS = 5000;

    /** @var list<array<string, mixed>> */
    public array $events = [];

    public bool $truncated = false;

    /**
     * Depth and open spans are per coroutine, not per buffer.
     *
     * One buffer serves a whole trace, and an SSE trace spans several coroutines
     * that run at the same time. Keyed by name alone, two coroutines opening
     * `pipeline` would share one start time: the second begin overwrites the
     * first, the first end consumes it, and the second end reports no duration at
     * all. A shared depth counter has the same problem in reverse -- it records
     * interleaved coroutines as one stack.
     *
     * @var array<int, int> coroutine id => current depth
     */
    public array $depths = [];

    /** @var array<int, array<string, float>> coroutine id => span name => start time in nanoseconds */
    public array $open = [];

    public bool $failed = false;

    /**
     * Per-trace query accounting, fed by the live QueryRecorder observer. Kept
     * on the buffer rather than derived from the shared recorder log, because
     * that log mixes queries from every coroutine on the worker — these two
     * count only what was attributed to THIS trace.
     */
    public int $queryCount = 0;

    public float $queryTotalMs = 0.0;

    /**
     * How many spans carrying the root name are currently open.
     *
     * A root-name span opening while a trace is already running is nested work,
     * not a second trace - an internal dispatch, a re-run of the handler chain.
     * Flushing on its end would cut the surrounding trace short at that point and
     * silently drop everything after it.
     */
    public int $rootOpen = 0;

    public function __construct(
        public readonly float $startedAt,
        public readonly int $rootCid,
        /** Name of the span that opened this buffer; closing it writes the trace. */
        public readonly string $rootSpan = 'request',
    ) {
    }

    /**
     * @param array<string, mixed> $event
     */
    public function push(array $event): void
    {
        if (count($this->events) >= self::MAX_EVENTS) {
            $this->truncated = true;

            return;
        }

        $this->events[] = $event;
    }

    public function depth(int $cid): int
    {
        return $this->depths[$cid] ?? 0;
    }

    public function enter(int $cid, string $name, float $startedAtNs): void
    {
        $this->open[$cid][$name] = $startedAtNs;
        $this->depths[$cid] = $this->depth($cid) + 1;
    }

    /** @return float|null start time in nanoseconds, or null if this coroutine never opened the span */
    public function leave(int $cid, string $name): ?float
    {
        $started = $this->open[$cid][$name] ?? null;
        unset($this->open[$cid][$name]);
        $this->depths[$cid] = max(0, $this->depth($cid) - 1);

        return $started;
    }

    public function sinceStartMs(): float
    {
        return round(((float) hrtime(true) - $this->startedAt) / 1_000_000, 3);
    }
}
