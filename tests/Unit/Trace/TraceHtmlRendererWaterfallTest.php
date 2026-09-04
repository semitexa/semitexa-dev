<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\TraceHtmlRenderer;

/**
 * Time is the horizontal axis: a bar sits at its offset into the request and is
 * as wide as its duration. These pin the geometry, not the styling.
 */
final class TraceHtmlRendererWaterfallTest extends TestCase
{
    #[Test]
    public function a_span_is_placed_at_its_offset_and_sized_by_its_duration(): void
    {
        // Request 0–10 ms; pipeline starts at 2 and takes 4 → left 20%, width 40%.
        $html = $this->render([
            $this->span('request', 0.0, 10.0, 0),
            $this->span('pipeline', 2.0, 4.0, 1),
        ]);

        self::assertStringContainsString('<i style="left:0.000%;width:100.000%"></i>', $html);
        self::assertStringContainsString('<i style="left:20.000%;width:40.000%"></i>', $html);
        self::assertStringContainsString('--depth:1', $html, 'nesting is indentation, not a separate column');
    }

    #[Test]
    public function a_row_that_names_a_class_shows_which_one_beside_the_step_name(): void
    {
        $html = $this->render([
            $this->span('request', 0.0, 10.0, 0),
            ['name' => 'pipeline.listener', 'depth' => 2, 'startMs' => 1.0, 'durationMs' => 1.0, 'cid' => 1, 'pcid' => 0, 'context' => ['listener' => 'App\\Auth\\AuthorizationListener', 'method' => 'handle']],
        ]);

        self::assertStringContainsString('pipeline.listener <span class="who">AuthorizationListener</span>', $html);
    }

    #[Test]
    public function rows_come_in_start_order_with_the_enclosing_span_first_on_a_tie(): void
    {
        $html = $this->render([
            $this->span('late', 6.0, 1.0, 1),
            $this->span('request', 0.0, 10.0, 0),
            $this->span('early', 0.0, 2.0, 1),
        ]);

        $request = strpos($html, '>request<');
        $early = strpos($html, '>early<');
        $late = strpos($html, '>late<');
        self::assertNotFalse($request);
        self::assertNotFalse($early);
        self::assertNotFalse($late);
        self::assertLessThan($early, $request, 'the root opens before what it encloses');
        self::assertLessThan($late, $early);
    }

    #[Test]
    public function a_mark_is_a_tick_and_an_open_span_runs_to_the_end(): void
    {
        $html = $this->render(
            [$this->span('request', 0.0, 10.0, 0), ['name' => 'pipeline', 'depth' => 1, 'startMs' => 7.0, 'durationMs' => null, 'cid' => 1, 'pcid' => 0, 'context' => []]],
            [['name' => 'request.exception', 'atMs' => 5.0, 'cid' => 1, 'pcid' => 0, 'context' => ['class' => 'App\\Boom']]],
        );

        self::assertStringContainsString('<b class="wf-tick" style="left:50.000%"></b>', $html);
        self::assertStringContainsString('<i class="open" style="left:70.000%;width:30.000%"></i>', $html, 'an unclosed span is drawn to the end and flagged');
        self::assertStringContainsString('class="node unfinished"', $html);
        self::assertStringContainsString('never closed', $html);
    }

    #[Test]
    public function a_row_on_another_coroutine_carries_a_chip(): void
    {
        $html = $this->render([
            $this->span('request', 0.0, 10.0, 0),
            ['name' => 'sse.frame', 'depth' => 1, 'startMs' => 1.0, 'durationMs' => 1.0, 'cid' => 7, 'pcid' => 1, 'context' => []],
        ]);

        self::assertStringContainsString('<em class="cid" title="coroutine 7">c7</em>', $html);
        self::assertStringContainsString('coroutine 7 (spawned by 1)', $html);
        self::assertSame(1, substr_count($html, 'class="cid"'), 'the root coroutine needs no chip');
    }

    #[Test]
    public function queries_get_one_lane_of_ticks_at_their_positions(): void
    {
        $html = $this->render(
            [$this->span('request', 0.0, 10.0, 0)],
            [],
            [
                ['sql' => 'SELECT 1', 'durationMs' => 1.0, 'params' => 0, 'atMs' => 5.0],
                ['sql' => 'SELECT 2', 'durationMs' => 0.5, 'params' => 0, 'atMs' => 8.0],
            ],
        );

        self::assertStringContainsString('2 queries', $html);
        self::assertStringContainsString('left:50.000%;width:10.000%', $html);
        self::assertStringContainsString('left:80.000%;width:5.000%', $html);
        self::assertStringContainsString('1.500 ms', $html, 'the lane totals the queries');
    }

    #[Test]
    public function queries_without_a_position_are_counted_but_not_pretended(): void
    {
        $html = $this->render(
            [$this->span('request', 0.0, 10.0, 0)],
            [],
            [['sql' => 'SELECT 1', 'durationMs' => 1.0, 'params' => 0, 'atMs' => null]],
        );

        self::assertStringContainsString('1 query (no positions recorded)', $html);
    }

    #[Test]
    public function the_ruler_labels_the_quarters_in_ms(): void
    {
        $html = $this->render([$this->span('request', 0.0, 200.0, 0)]);

        self::assertStringContainsString('<b style="left:50%"><span>100.0 ms</span></b>', $html);
        self::assertStringContainsString('<b style="left:100%"><span>200.0 ms</span></b>', $html);
    }

    /**
     * @param list<array<string, mixed>> $spans
     * @param list<array<string, mixed>> $marks
     * @param list<array<string, mixed>> $queries
     */
    private function render(array $spans, array $marks = [], array $queries = []): string
    {
        $total = 0.0;
        foreach ($spans as $s) {
            $total = max($total, (float) $s['startMs'] + (float) ($s['durationMs'] ?? 0.0));
        }

        return (new TraceHtmlRenderer())->renderTrace([
            'meta' => ['file' => 't.json', 'recordedAt' => '2026-09-04T00:00:00+00:00', 'path' => '/x', 'method' => 'GET', 'route' => 'x', 'totalMs' => $total],
            'spans' => $spans,
            'marks' => $marks,
            'queries' => $queries,
        ]);
    }

    /** @return array<string, mixed> */
    private function span(string $name, float $start, float $dur, int $depth): array
    {
        return ['name' => $name, 'depth' => $depth, 'startMs' => $start, 'durationMs' => $dur, 'cid' => 1, 'pcid' => 0, 'context' => []];
    }
}
