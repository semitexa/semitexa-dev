<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Log\LogOrigin;
use Semitexa\Dev\Application\Service\Trace\LogOriginAttribution;
use Semitexa\Dev\Application\Service\Trace\ObservatoryContext;
use Semitexa\Dev\Application\Service\Trace\TraceBuffer;
use Semitexa\Dev\Application\Service\Trace\TraceContext;

/**
 * The half that can answer core's question.
 *
 * Outside a coroutine both contexts fall back to a process-local slot, which is
 * what lets these run under PHPUnit at all; cid is 0 there, and that is the key
 * the buffer is filled under.
 */
final class LogOriginAttributionTest extends TestCase
{
    protected function tearDown(): void
    {
        ObservatoryContext::reset();
        TraceContext::resetFallback();
        LogOrigin::resolveWith(null);
    }

    private function buffer(): TraceBuffer
    {
        return new TraceBuffer(startedAt: (float) hrtime(true), rootCid: 0, rootSpan: 'request');
    }

    #[Test]
    public function the_innermost_open_span_is_the_block(): void
    {
        $buffer = $this->buffer();
        TraceContext::begin($buffer);
        $buffer->enter(0, 'request', 0.0);
        $buffer->enter(0, 'pipeline.listener', 0.0);

        self::assertSame('pipeline.listener', LogOriginAttribution::current()['block']);
    }

    #[Test]
    public function leaving_a_span_hands_the_block_back_to_the_one_outside_it(): void
    {
        $buffer = $this->buffer();
        TraceContext::begin($buffer);
        $buffer->enter(0, 'request', 0.0);
        $buffer->enter(0, 'pipeline.handler', 0.0);
        $buffer->leave(0, 'pipeline.handler');

        self::assertSame(
            'request',
            LogOriginAttribution::current()['block'],
            'a line logged after the handler returned belongs to the request, not to the handler',
        );
    }

    #[Test]
    public function the_process_id_rides_along_when_one_is_open(): void
    {
        ObservatoryContext::open(['id' => 'p-17-abc', 'kind' => 'http']);

        self::assertSame(['process' => 'p-17-abc'], LogOriginAttribution::current());
    }

    #[Test]
    public function reading_the_process_id_does_not_end_the_process(): void
    {
        // currentId() must not be close() in disguise: close() decrements the
        // nesting count, so a reader built on it would end processes by
        // looking at them.
        ObservatoryContext::open(['id' => 'p-17-abc']);
        LogOriginAttribution::current();
        LogOriginAttribution::current();

        self::assertSame(['id' => 'p-17-abc'], ObservatoryContext::close());
    }

    #[Test]
    public function a_process_with_no_trace_reports_no_block(): void
    {
        // The common case: stage mode off, so there are no spans to be inside.
        // Omitting the key is the honest answer; inventing a stage from the
        // message would not be.
        ObservatoryContext::open(['id' => 'p-17-abc']);

        self::assertArrayNotHasKey('block', LogOriginAttribution::current());
    }

    #[Test]
    public function outside_everything_there_is_nothing_to_say(): void
    {
        self::assertNull(
            LogOriginAttribution::current(),
            'a worker booting or a CLI command has no process and no block, and that is not a failure',
        );
    }

    #[Test]
    public function installing_is_idempotent_and_reaches_core(): void
    {
        ObservatoryContext::open(['id' => 'p-17-abc']);
        LogOriginAttribution::install();
        LogOriginAttribution::install();

        self::assertTrue(LogOrigin::isResolvable());
        self::assertSame(['process' => 'p-17-abc'], LogOrigin::current());
    }
}
