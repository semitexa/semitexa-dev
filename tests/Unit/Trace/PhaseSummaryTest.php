<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\PhaseSummary;

final class PhaseSummaryTest extends TestCase
{
    #[Test]
    public function folds_stage_spans_queries_handler_and_outcome(): void
    {
        $events = [
            ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/']],
            ['type' => 'end', 'name' => 'auth.pre_hydration_gate', 'durationMs' => 0.02],
            ['type' => 'end', 'name' => 'payload.hydrate_and_validate', 'durationMs' => 0.05],
            ['type' => 'end', 'name' => 'resource.resolve', 'durationMs' => 0.03],
            ['type' => 'end', 'name' => 'pipeline.auth_check', 'durationMs' => 0.4],
            ['type' => 'end', 'name' => 'pipeline.listener', 'durationMs' => 0.1],
            ['type' => 'end', 'name' => 'pipeline.listener', 'durationMs' => 0.3],
            ['type' => 'begin', 'name' => 'pipeline.handler', 'context' => ['handler' => 'App\\Demo\\HomeHandler']],
            ['type' => 'query', 'name' => 'orm.query', 'durationMs' => 1.5],
            ['type' => 'query', 'name' => 'orm.query', 'durationMs' => 0.5],
            ['type' => 'end', 'name' => 'pipeline.handler', 'durationMs' => 2.6],
            ['type' => 'end', 'name' => 'pipeline.handler_completed', 'durationMs' => 0.03],
            ['type' => 'end', 'name' => 'response.render', 'durationMs' => 0.7],
            ['type' => 'end', 'name' => 'request', 'durationMs' => 4.3],
        ];

        self::assertSame([
            'gate' => 0.02, 'hydrate' => 0.05, 'resolve' => 0.03, 'auth' => 0.4,
            'listeners' => 0.4, 'handler' => 2.6, 'completed' => 0.03, 'render' => 0.7,
            'n' => 2, 'q' => 2, 'qms' => 2.0, 'by' => 'HomeHandler', 'outcome' => 'ok',
        ], PhaseSummary::fold($events));
    }

    #[Test]
    public function an_exception_mark_names_the_outcome_and_the_class(): void
    {
        $out = PhaseSummary::fold([
            ['type' => 'end', 'name' => 'pipeline.handler', 'durationMs' => 1.0],
            ['type' => 'mark', 'name' => 'request.exception', 'context' => ['class' => 'Semitexa\\Core\\Exception\\NotFoundException']],
        ]);

        self::assertSame('exception', $out['outcome']);
        self::assertSame('NotFoundException', $out['detail']);
    }

    #[Test]
    public function a_validation_short_circuit_is_rejected_unless_something_threw(): void
    {
        $rejected = PhaseSummary::fold([
            ['type' => 'end', 'name' => 'payload.hydrate_and_validate', 'durationMs' => 0.1],
            ['type' => 'mark', 'name' => 'request.short_circuit', 'context' => ['reason' => 'validation']],
        ]);
        self::assertSame(['rejected', 'validation'], [$rejected['outcome'], $rejected['detail']]);

        $threw = PhaseSummary::fold([
            ['type' => 'mark', 'name' => 'request.exception', 'context' => ['class' => 'RuntimeException']],
            ['type' => 'mark', 'name' => 'request.short_circuit', 'context' => ['reason' => 'validation']],
            ['type' => 'end', 'name' => 'response.render', 'durationMs' => 0.1],
        ]);
        self::assertSame('exception', $threw['outcome'], 'an exception is never downgraded to a rejection');
    }

    #[Test]
    public function queued_work_is_counted_from_both_hand_off_marks(): void
    {
        $out = PhaseSummary::fold([
            ['type' => 'end', 'name' => 'pipeline.handler', 'durationMs' => 0.2],
            ['type' => 'mark', 'name' => 'event.listener.queued', 'context' => ['listener' => 'A']],
            ['type' => 'mark', 'name' => 'event.listener.queued', 'context' => ['listener' => 'B']],
            ['type' => 'mark', 'name' => 'pipeline.handler.queued', 'context' => ['handler' => 'H']],
        ]);

        self::assertSame(3, $out['queued']);
        self::assertSame('queued', $out['outcome'], 'a queued handler is the outcome; queued listeners alone would leave it ok');
    }

    #[Test]
    public function nothing_recognisable_folds_to_nothing(): void
    {
        // A trace made only of marks and unknown spans must not produce a
        // phases key on the journal line — the panel treats [] as "no data".
        self::assertSame([], PhaseSummary::fold([
            ['type' => 'begin', 'name' => 'request'],
            ['type' => 'mark', 'name' => 'event.dispatch'],
            ['type' => 'end', 'name' => 'something.custom', 'durationMs' => 3.0],
        ]));
    }
}
