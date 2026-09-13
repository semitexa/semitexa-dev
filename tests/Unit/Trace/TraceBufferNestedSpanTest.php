<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\TraceBuffer;

/**
 * A span can open inside a span of its own name. A nested dispatch re-enters
 * `request` — RequestTracer counts that case explicitly, so it is not
 * hypothetical — and a handler that renders a template which renders a template
 * does the same one level down.
 *
 * Keyed by name, the second begin overwrote the first's start time and the
 * first end unset the entry for both: the inner span reported the outer's
 * duration, the outer reported none, and every log line after that point was
 * attributed to whatever was left on the map rather than to the span actually
 * running. The per-coroutine keying already documented in TraceBuffer fixed
 * exactly this between coroutines; this is the same defect inside one.
 */
final class TraceBufferNestedSpanTest extends TestCase
{
    private function buffer(): TraceBuffer
    {
        return new TraceBuffer(startedAt: (float) hrtime(true), rootCid: 0, rootSpan: 'request');
    }

    #[Test]
    public function the_inner_span_closes_first_and_gets_its_own_start_time(): void
    {
        $buffer = $this->buffer();
        $buffer->enter(1, 'request', 100.0);
        $buffer->enter(1, 'request', 700.0);

        self::assertSame(700.0, $buffer->leave(1, 'request'), 'the inner span opened at 700');
        self::assertSame(100.0, $buffer->leave(1, 'request'), 'and the outer one is still the one that opened at 100');
    }

    #[Test]
    public function the_outer_span_is_still_open_after_the_inner_one_closes(): void
    {
        $buffer = $this->buffer();
        $buffer->enter(1, 'request', 100.0);
        $buffer->enter(1, 'pipeline.handler', 200.0);
        $buffer->enter(1, 'request', 300.0);
        $buffer->leave(1, 'request');

        self::assertSame(
            'pipeline.handler',
            $buffer->innermostOpen(1),
            'attribution falls back to the span the nested dispatch was made from',
        );
    }

    #[Test]
    public function depth_follows_the_nesting(): void
    {
        $buffer = $this->buffer();
        $buffer->enter(1, 'request', 100.0);
        $buffer->enter(1, 'request', 200.0);

        self::assertSame(2, $buffer->depth(1));

        $buffer->leave(1, 'request');
        self::assertSame(1, $buffer->depth(1), 'one of the two is still open');

        $buffer->leave(1, 'request');
        self::assertSame(0, $buffer->depth(1));
    }

    #[Test]
    public function nothing_is_open_once_both_have_closed(): void
    {
        $buffer = $this->buffer();
        $buffer->enter(1, 'request', 100.0);
        $buffer->enter(1, 'request', 200.0);
        $buffer->leave(1, 'request');
        $buffer->leave(1, 'request');

        self::assertNull($buffer->innermostOpen(1));
    }

    /**
     * Siblings, not nesting: the same name opened and closed twice in a row is
     * the shape a loop produces, and each pass must report its own duration.
     */
    #[Test]
    public function a_repeated_sibling_span_reports_each_pass_separately(): void
    {
        $buffer = $this->buffer();

        $buffer->enter(1, 'orm.query', 100.0);
        self::assertSame(100.0, $buffer->leave(1, 'orm.query'));

        $buffer->enter(1, 'orm.query', 500.0);
        self::assertSame(500.0, $buffer->leave(1, 'orm.query'));
    }

    #[Test]
    public function an_end_without_a_begin_says_so_and_changes_nothing(): void
    {
        $buffer = $this->buffer();
        $buffer->enter(1, 'request', 100.0);

        self::assertNull($buffer->leave(1, 'never.opened'));
        self::assertSame(1, $buffer->depth(1), 'the open span is still one level deep');
        self::assertSame('request', $buffer->innermostOpen(1));
    }

    #[Test]
    public function coroutines_remain_independent(): void
    {
        $buffer = $this->buffer();
        $buffer->enter(1, 'pipeline', 100.0);
        $buffer->enter(2, 'pipeline', 200.0);

        self::assertSame(200.0, $buffer->leave(2, 'pipeline'));
        self::assertSame(100.0, $buffer->leave(1, 'pipeline'));
    }
}
