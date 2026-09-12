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
    public function an_exception_mapped_to_a_refusal_status_is_a_refusal_not_a_crash(): void
    {
        // A pre-hydration gate declines by throwing — it has no other way to
        // stop the pipeline — so without the mapped status a 401 and a service
        // blowing up are the same event to every reader of this summary.
        $out = PhaseSummary::fold([
            ['type' => 'mark', 'name' => 'request.exception', 'context' => ['class' => 'Semitexa\\Authorization\\Exception\\AuthenticationRequiredException']],
            ['type' => 'mark', 'name' => 'request.exception.mapped', 'context' => ['status' => 401]],
        ]);

        self::assertSame('rejected', $out['outcome']);
        self::assertSame(
            'AuthenticationRequiredException',
            $out['detail'],
            'the class that refused is more use to a reader than the word "refused"',
        );
    }

    #[Test]
    public function an_exception_mapped_to_a_server_error_stays_a_crash(): void
    {
        $out = PhaseSummary::fold([
            ['type' => 'mark', 'name' => 'request.exception', 'context' => ['class' => 'RuntimeException']],
            ['type' => 'mark', 'name' => 'request.exception.mapped', 'context' => ['status' => 500]],
        ]);

        self::assertSame('exception', $out['outcome']);
    }

    #[Test]
    public function a_missing_route_is_not_a_refusal(): void
    {
        // "No such thing" is not "not for you". Were 404 in the refusal set,
        // every unrouted probe on a public site would flood the refusal ring.
        $out = PhaseSummary::fold([
            ['type' => 'mark', 'name' => 'request.exception', 'context' => ['class' => 'Semitexa\\Core\\Exception\\NotFoundException']],
            ['type' => 'mark', 'name' => 'request.exception.mapped', 'context' => ['status' => 404]],
        ]);

        self::assertSame('exception', $out['outcome']);
    }

    #[Test]
    public function a_mapped_status_cannot_promote_a_request_that_never_threw(): void
    {
        // The branch is guarded on outcome === 'exception'. A stray mark must
        // not be able to turn a served request into a refused one.
        $out = PhaseSummary::fold([
            ['type' => 'end', 'name' => 'pipeline.handler', 'durationMs' => 1.0],
            ['type' => 'mark', 'name' => 'request.exception.mapped', 'context' => ['status' => 403]],
        ]);

        self::assertSame('ok', $out['outcome']);
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
    public function a_request_that_ended_badly_before_any_span_closed_still_reports_it(): void
    {
        // Rejected during hydration: no stage span ever closed, no query ran,
        // no handler was reached. Dropping the summary here made the panel
        // call that request 'ok'.
        $rejected = PhaseSummary::fold([
            ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/']],
            ['type' => 'mark', 'name' => 'request.short_circuit', 'context' => ['reason' => 'validation']],
        ]);
        self::assertSame(['outcome' => 'rejected', 'detail' => 'validation'], $rejected);

        $threw = PhaseSummary::fold([
            ['type' => 'mark', 'name' => 'request.exception', 'context' => ['class' => 'RuntimeException']],
        ]);
        self::assertSame('exception', $threw['outcome']);
        self::assertSame('RuntimeException', $threw['detail']);
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
