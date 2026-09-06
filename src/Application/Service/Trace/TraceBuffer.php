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
     * buffer is capped. Memory is not negotiable: a diagnostic tool that can
     * exhaust the process it observes is worse than one that stops early.
     *
     * WHICH events the cap keeps is negotiable, and the first answer was wrong.
     * It kept the FIRST 5000 and dropped everything after, so a long trace lost
     * exactly the part the developer opened it for: the mutation they triggered
     * after setting the page up, the orm.summary mark that is the only place
     * queryCount and queryTotalMs are written, and the root end event carrying
     * the total. A two-hour SSE connection that filled up at minute four
     * reported its first four minutes and called it the request.
     *
     * It is now a ring that keeps the LAST events instead, so what is dropped is
     * the warm-up.
     */
    public const MAX_EVENTS = 5000;

    /**
     * How many leading events are pinned and never evicted.
     *
     * The trace file's identity lives in the root begin event and nowhere else:
     * TraceReader reads path, method and route out of its context, and the
     * renderer takes the root coroutine id from it. Ring those away and the
     * trace becomes an anonymous list of spans. It is always the first event
     * pushed — the buffer is created by the root span's own begin() — which
     * {@see \Semitexa\Dev\Tests\Unit\Trace\RequestTracerTest} pins.
     */
    public const PINNED_EVENTS = 1;

    /**
     * Pinned events followed by the ring, in storage order — NOT chronological
     * once the ring has wrapped. Read it through {@see events()}.
     *
     * @var list<array<string, mixed>>
     */
    private array $stored = [];

    /** Next ring slot to overwrite, relative to the end of the pinned block. */
    private int $ringAt = 0;

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
        if (count($this->stored) < self::MAX_EVENTS) {
            $this->stored[] = $event;

            return;
        }

        // Overwrite the oldest evictable slot in place. Not array_splice(): that
        // is O(n) per event, and the case this exists for is the trace that
        // pushes a hundred thousand of them.
        $this->stored[self::PINNED_EVENTS + $this->ringAt] = $event;
        $this->ringAt = ($this->ringAt + 1) % (self::MAX_EVENTS - self::PINNED_EVENTS);
        $this->truncated = true;
    }

    /**
     * The events in chronological order: the pinned head, then the ring read
     * from its oldest surviving slot.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        if (!$this->truncated) {
            return $this->stored;
        }

        $pinned = array_slice($this->stored, 0, self::PINNED_EVENTS);
        $ring = array_slice($this->stored, self::PINNED_EVENTS);

        return array_merge(
            $pinned,
            array_slice($ring, $this->ringAt),
            array_slice($ring, 0, $this->ringAt),
        );
    }

    /**
     * Which end of the trace the cap removed, for the viewer to say out loud.
     * A reader who is not told assumes they are seeing the whole request.
     */
    public function truncatedEnd(): ?string
    {
        return $this->truncated ? 'head' : null;
    }

    public function eventCount(): int
    {
        return count($this->stored);
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
