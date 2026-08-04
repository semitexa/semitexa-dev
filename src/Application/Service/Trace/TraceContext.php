<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * Finds the buffer a span belongs to, across coroutine boundaries.
 *
 * ## The problem this solves
 *
 * Swoole does not inherit coroutine context: a coroutine spawned to stream a
 * deferred block reads null for a key its parent set. Measured, not assumed.
 *
 * What it does provide is `getPcid()` — the id of the coroutine that spawned this
 * one — and `getContext($cid)`, which reads *another* coroutine's context. So a
 * child can walk up the chain until it finds the buffer an ancestor stored.
 *
 * That is what makes correlation free: nothing at the spawn sites has to change.
 * `Coroutine::create` in LivePubSubChannelController, SseSessionCoroutines,
 * ResourceInvalidationSubscriber and the deferred block machinery all stay
 * untouched, and their work still lands in the right trace.
 *
 * ## Where it stops
 *
 * `getContext()` on a coroutine that has already finished returns null, so a span
 * recorded after its ancestor ended — an SSE session outliving its request — finds
 * nothing and is dropped rather than attributed to whatever request happens to be
 * running. Guessing there would put one request's work in another request's trace,
 * which is worse than a gap: a gap is visible, a wrong attribution is not.
 */
final class TraceContext
{
    private const KEY = '__semitexa_trace_buffer';

    /** Depth guard: a pathological chain must not turn span recording into a walk. */
    private const MAX_HOPS = 32;

    /** CLI and tests have no coroutines; a process-local slot stands in. */
    private static ?TraceBuffer $fallback = null;

    public static function begin(TraceBuffer $buffer): void
    {
        if (!self::inCoroutine()) {
            self::$fallback = $buffer;

            return;
        }

        $context = \Swoole\Coroutine::getContext();
        if ($context !== null) {
            $context[self::KEY] = $buffer;
        }
    }

    /**
     * The buffer for the current coroutine, or the nearest ancestor that has one.
     */
    public static function current(): ?TraceBuffer
    {
        if (!self::inCoroutine()) {
            return self::$fallback;
        }

        $cid = \Swoole\Coroutine::getCid();
        $hops = 0;

        while ($cid > 0 && $hops < self::MAX_HOPS) {
            $context = \Swoole\Coroutine::getContext($cid);
            $found = $context[self::KEY] ?? null;
            if ($found instanceof TraceBuffer) {
                return $found;
            }

            $cid = \Swoole\Coroutine::getPcid($cid);
            $hops++;
        }

        return null;
    }

    public static function end(): void
    {
        if (!self::inCoroutine()) {
            self::$fallback = null;

            return;
        }

        $context = \Swoole\Coroutine::getContext();
        if ($context !== null) {
            unset($context[self::KEY]);
        }
    }

    /**
     * Identity of the coroutine a span is being recorded from, so the viewer can
     * tell concurrent work from sequential work rather than inferring it from
     * timings that happen to overlap.
     *
     * @return array{cid: int, pcid: int}
     */
    public static function identity(): array
    {
        if (!self::inCoroutine()) {
            return ['cid' => 0, 'pcid' => 0];
        }

        $cid = \Swoole\Coroutine::getCid();
        $pcid = \Swoole\Coroutine::getPcid($cid);

        return ['cid' => $cid, 'pcid' => is_int($pcid) ? $pcid : 0];
    }

    public static function resetFallback(): void
    {
        self::$fallback = null;
    }

    private static function inCoroutine(): bool
    {
        return class_exists(\Swoole\Coroutine::class, false)
            && \Swoole\Coroutine::getCid() > 0;
    }
}
