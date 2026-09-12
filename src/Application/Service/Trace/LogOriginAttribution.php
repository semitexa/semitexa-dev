<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Log\LogOrigin;

/**
 * Answers core's "where was this line logged from" with what the tracer knows.
 *
 * Core owns the slot and nothing else ({@see LogOrigin}); this is the half that
 * can actually answer, and it lives here because the answer comes from the
 * trace buffer and the process journal, both of which are dev's.
 *
 * ## How the answer is found
 *
 * The block is not inferred from the message, the channel or the class that
 * logged. At the instant a line is written, the calling coroutine is inside
 * some set of open spans, and the innermost of those IS the block — the tracer
 * has been recording it all along for its own waterfall. So attribution costs
 * a lookup, not a heuristic, and a line written from a listener says
 * `pipeline.listener` because that is literally where it was.
 *
 * ## Two honest gaps, both deliberate
 *
 * 1. **No spans, no block.** Spans exist only while a trace is being recorded
 *    — stage mode, or a request carrying the marker. Without one there is a
 *    process but no block, and the answer says so by omitting `block` rather
 *    than guessing a stage from the message.
 *
 * 2. **A child coroutine may have a block but no process.**
 *    {@see TraceContext::current()} walks up the spawn chain, so a coroutine
 *    spawned mid-request still finds its ancestor's buffer; but
 *    {@see ObservatoryContext} reads the current coroutine only, so the
 *    process id is absent there. Both halves are optional in the shape core
 *    accepts, and a partial answer is worth more than a confident wrong one —
 *    attributing a child's line to whichever process happens to be running in
 *    this worker would put one request's logs under another request's name,
 *    and unlike a gap that is invisible.
 */
final class LogOriginAttribution
{
    /**
     * Install the resolver once per worker.
     *
     * Called from {@see RequestTracer::begin()} rather than from a boot hook
     * because dev has none, and because the tracer is the thing whose presence
     * makes the answer possible in the first place: if it never runs, nothing
     * would have been resolvable anyway.
     */
    public static function install(): void
    {
        if (LogOrigin::isResolvable()) {
            return;
        }

        LogOrigin::resolveWith(static fn (): ?array => self::current());
    }

    /** @return array{process?: string, block?: string}|null */
    public static function current(): ?array
    {
        $origin = [];

        $process = ObservatoryContext::currentId();
        if ($process !== null) {
            $origin['process'] = $process;
        }

        $buffer = TraceContext::current();
        if ($buffer !== null) {
            $block = $buffer->innermostOpen(TraceContext::identity()['cid']);
            if ($block !== null) {
                $origin['block'] = $block;
            }
        }

        return $origin === [] ? null : $origin;
    }
}
