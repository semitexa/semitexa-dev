<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Payload\Request\TraceNodePayload;
use Semitexa\Dev\Application\Service\Trace\SourceSlice;
use Semitexa\Dev\Application\Service\Trace\TraceHtmlRenderer;

/**
 * The Source section of `/__trace/node`: numbered from the file's own line,
 * escaped, with a toggle that goes exactly one way.
 */
final class TraceHtmlRendererSourceTest extends TestCase
{
    #[Test]
    public function a_method_slice_is_numbered_from_its_own_first_line(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode(
            'App\\Handler\\ThingHandler',
            $this->node(),
            't.json',
            true,
            $this->slice(method: 'handle', start: 41),
        );

        self::assertStringContainsString('<ol start="41">', $html);
        self::assertStringContainsString('<code>handle()</code>', $html);
        self::assertStringContainsString('src/Handler/ThingHandler.php:41–43', $html);
        self::assertStringContainsString('ThingHandler::handle', $html);
        // Toggle goes to the class, keeping the trace to come back to.
        self::assertStringContainsString(
            'href="/__trace/node?class=App%5CHandler%5CThingHandler&amp;from=t.json&amp;scope=class"',
            $html,
        );
        self::assertStringNotContainsString('scope=method', $html);
    }

    #[Test]
    public function the_class_view_offers_the_way_back_to_the_method(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode(
            'App\\Handler\\ThingHandler',
            $this->node(),
            't.json',
            true,
            $this->slice(method: null, start: 12),
            classScope: true,
        );

        self::assertStringContainsString('whole class', $html);
        self::assertStringContainsString('scope=method"', $html);
        self::assertStringNotContainsString('scope=class"', $html);
    }

    #[Test]
    public function no_entry_method_means_the_class_with_no_toggle(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode(
            'App\\Handler\\ThingHandler',
            $this->node(),
            't.json',
            true,
            $this->slice(method: null, start: 12),
        );

        self::assertStringContainsString('no conventional entry method', $html);
        self::assertStringNotContainsString('class="src-toggle"', $html);
    }

    #[Test]
    public function source_is_escaped_and_highlighted(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode(
            'App\\Handler\\ThingHandler',
            $this->node(),
            '',
            true,
            $this->slice(method: 'handle', start: 1, lines: ['$x = "<script>";']),
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('<span class="v">$x</span>', $html);
    }

    #[Test]
    public function a_truncated_slice_says_so(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode(
            'App\\Handler\\ThingHandler',
            $this->node(),
            '',
            true,
            $this->slice(method: null, start: 1, truncated: true),
        );

        self::assertStringContainsString('Cut at 3 lines', $html);
    }

    #[Test]
    public function source_without_a_graph_node_is_still_a_page(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode(
            'App\\Handler\\ThingHandler',
            null,
            't.json',
            true,
            $this->slice(method: 'handle', start: 41),
        );

        self::assertStringContainsString('<ol start="41">', $html);
        self::assertStringContainsString('not in graph', $html);
        self::assertStringContainsString('ai:review-graph:generate', $html);
        self::assertStringNotContainsString('<h1>Not in the graph</h1>', $html, 'the empty state is for when there is nothing at all to show');
    }

    #[Test]
    public function nothing_at_all_is_the_empty_state(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode('App\\Nope', null, '', true, null);

        self::assertStringContainsString('<h1>Not in the graph</h1>', $html);
    }

    #[Test]
    public function a_node_without_source_says_the_source_is_unavailable(): void
    {
        $html = (new TraceHtmlRenderer())->renderNode('App\\Handler\\ThingHandler', $this->node(), '', true, null);

        self::assertStringContainsString('Source unavailable', $html);
        self::assertStringContainsString('Reaches', $html);
    }

    #[Test]
    public function the_payload_only_knows_two_scopes(): void
    {
        $payload = new TraceNodePayload();
        self::assertFalse($payload->wantsClass());

        $payload->setScope('class');
        self::assertTrue($payload->wantsClass());

        $payload->setScope('everything');
        self::assertFalse($payload->wantsClass(), 'an unknown scope is the default, not a third view');

        $payload->setMethod(['not', 'a', 'string']);
        self::assertSame('', $payload->method);
    }

    /**
     * @param list<string>|null $lines
     */
    private function slice(?string $method, int $start, ?array $lines = null, bool $truncated = false): SourceSlice
    {
        $lines ??= ['public function handle(): void', '{', '}'];

        return new SourceSlice(
            fqcn: 'App\\Handler\\ThingHandler',
            method: $method,
            file: 'src/Handler/ThingHandler.php',
            startLine: $start,
            endLine: $start + count($lines) - 1,
            lines: $lines,
            truncated: $truncated,
            origin: 'reflection',
        );
    }

    /**
     * @return array{fqcn: string, name: string, type: string, module: string, file: string, line: int, out: list<array{kind: string, fqcn: string, name: string, type: string}>, in: list<array{kind: string, fqcn: string, name: string, type: string}>}
     */
    private function node(): array
    {
        return [
            'fqcn' => 'App\\Handler\\ThingHandler',
            'name' => 'ThingHandler',
            'type' => 'handler',
            'module' => 'Thing',
            'file' => 'src/Handler/ThingHandler.php',
            'line' => 12,
            'out' => [['kind' => 'handles', 'fqcn' => 'App\\Payload\\ThingPayload', 'name' => 'ThingPayload', 'type' => 'payload']],
            'in' => [],
        ];
    }
}
