<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\TraceHtmlRenderer;

/**
 * The link from a recorded step to the class it ran.
 *
 * The rule these pin down: a step that names a class is clickable, a step that
 * does not is not. Rendering every step as a link would promise an answer the
 * viewer cannot give.
 */
final class TraceHtmlRendererGraphLinkTest extends TestCase
{
    #[Test]
    public function a_step_that_names_a_class_links_to_it(): void
    {
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('pipeline', ['handler' => 'App\\Handler\\ThingHandler']),
        ]));

        self::assertStringContainsString('<a class="node', $html);
        self::assertStringContainsString(
            'href="/__trace/node?class=App%5CHandler%5CThingHandler&amp;from=t.json"',
            $html,
        );
    }

    #[Test]
    public function a_step_that_names_no_class_is_not_a_link(): void
    {
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('response.render', []),
        ]));

        self::assertStringNotContainsString('<a class="node', $html);
        self::assertStringContainsString('<div class="node', $html);
    }

    #[Test]
    public function the_handler_wins_when_a_step_names_several_classes(): void
    {
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('pipeline', [
                'resource' => 'App\\Resource\\ThingResource',
                'handler' => 'App\\Handler\\ThingHandler',
            ]),
        ]));

        self::assertStringContainsString('class=App%5CHandler%5CThingHandler', $html);
        self::assertStringNotContainsString('class=App%5CResource%5CThingResource', $html);
    }

    /**
     * Matching on shape rather than on a list of known context keys is what keeps
     * a span added later linkable without editing the renderer — but it must not
     * turn a namespaced string that is plainly not a class into a link.
     */
    #[Test]
    public function a_value_that_only_looks_namespaced_is_not_treated_as_a_class(): void
    {
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('custom', ['reason' => 'origin\\ not allowed']),
        ]));

        self::assertStringNotContainsString('<a class="node', $html);
    }

    #[Test]
    public function a_class_the_graph_does_not_know_says_so_rather_than_failing(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode('App\\Gone', null, 't.json', true);

        self::assertStringContainsString('is not in the project graph', $html);
        self::assertStringContainsString('ai:review-graph:generate', $html);
        self::assertStringContainsString('href="/__trace?file=t.json"', $html);
    }

    #[Test]
    public function no_graph_at_all_is_reported_as_a_missing_graph_not_a_missing_class(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode('App\\Thing', null, '', false);

        self::assertStringContainsString('No project graph was found', $html);
    }

    #[Test]
    public function a_node_lists_both_directions_of_its_edges(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode('App\\Handler\\ThingHandler', [
            'fqcn' => 'App\\Handler\\ThingHandler',
            'name' => 'ThingHandler',
            'type' => 'handler',
            'module' => 'App',
            'file' => 'src/Handler/ThingHandler.php',
            'line' => 12,
            'out' => [
                ['kind' => 'handles', 'fqcn' => 'App\\Payload\\ThingPayload', 'name' => 'ThingPayload', 'type' => 'payload'],
            ],
            'in' => [
                ['kind' => 'instantiates', 'fqcn' => 'App\\Tests\\ThingHandlerTest', 'name' => 'ThingHandlerTest', 'type' => 'class'],
            ],
        ], 't.json', true);

        self::assertStringContainsString('Reaches', $html);
        self::assertStringContainsString('Reached by', $html);
        self::assertStringContainsString('class=App%5CPayload%5CThingPayload', $html);
        self::assertStringContainsString('class=App%5CTests%5CThingHandlerTest', $html);
        self::assertStringContainsString('src/Handler/ThingHandler.php', $html);
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */

    #[Test]
    public function a_step_that_names_its_method_links_to_that_method(): void
    {
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('pipeline', ['handler' => 'App\\Handler\\ThingHandler', 'method' => 'handle']),
        ]));

        self::assertStringContainsString(
            'href="/__trace/node?class=App%5CHandler%5CThingHandler&amp;from=t.json&amp;method=handle"',
            $html,
        );
        self::assertStringContainsString('ThingHandler::handle()', $html);
    }

    #[Test]
    public function an_http_verb_is_not_a_method(): void
    {
        // A class beside a verb-shaped method key: the verb must not become a link target.
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('request', ['method' => 'GET', 'path' => '/x', 'gate' => 'App\\Auth\\Gate']),
        ]));

        self::assertStringNotContainsString('method=GET', $html);
        self::assertStringContainsString('class=App%5CAuth%5CGate&amp;from=t.json"', $html);
    }

    #[Test]
    public function a_listener_span_links_to_the_listener_not_the_event(): void
    {
        $html = (new TraceHtmlRenderer())->renderTrace($this->trace([
            $this->span('event.listener', ['event' => 'App\\Event\\Thing', 'listener' => 'App\\Listener\\OnThing', 'method' => 'handle']),
        ]));

        self::assertStringContainsString('class=App%5CListener%5COnThing&amp;from=t.json&amp;method=handle"', $html);
    }

    private function span(string $name, array $context): array
    {
        return [
            'name' => $name,
            'depth' => 1,
            'startMs' => 0.1,
            'durationMs' => 1.0,
            'cid' => 1,
            'pcid' => 0,
            'context' => $context,
        ];
    }

    /**
     * @param  list<array<string, mixed>> $spans
     * @return array<string, mixed>
     */
    private function trace(array $spans): array
    {
        array_unshift($spans, [
            'name' => 'request',
            'depth' => 0,
            'startMs' => 0.0,
            'durationMs' => 5.0,
            'cid' => 1,
            'pcid' => 0,
            'context' => ['path' => '/x', 'method' => 'GET'],
        ]);

        return [
            'meta' => [
                'file' => 't.json',
                'recordedAt' => '2026-08-04T00:00:00+00:00',
                'path' => '/x',
                'method' => 'GET',
                'route' => 'ThingPayload',
                'totalMs' => 5.0,
            ],
            'spans' => $spans,
            'marks' => [],
            'queries' => [],
        ];
    }
}
