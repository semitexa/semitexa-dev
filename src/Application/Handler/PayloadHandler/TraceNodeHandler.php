<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\TraceNodePayload;
use Semitexa\Dev\Application\Service\Trace\EntryMethodCatalog;
use Semitexa\Dev\Application\Service\Trace\SourceSlice;
use Semitexa\Dev\Application\Service\Trace\SourceSliceReader;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;
use Semitexa\Dev\Application\Service\Trace\TraceHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\TraceReader;

/**
 * Serves one graph node at `/__trace/node`: what the class is wired to (from
 * the project graph) and what it does (its source, read live).
 *
 * Answers 404 rather than 403 outside dev, for the same reason as
 * {@see TraceHandler}: a "forbidden" confirms the route exists. The gate
 * matters more here than on the trace list — this page reads source files.
 */
#[AsPayloadHandler(payload: TraceNodePayload::class, resource: ResourceResponse::class)]
final class TraceNodeHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected TraceReader $reader;

    #[InjectAsReadonly]
    protected TraceGraphReader $graph;

    #[InjectAsReadonly]
    protected SourceSliceReader $source;

    #[InjectAsReadonly]
    protected TraceHtmlRenderer $renderer;

    public function handle(TraceNodePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->reader->isEnabled()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $node = $payload->class !== '' ? $this->graph->describe($payload->class) : null;
        $slice = $payload->class !== '' ? $this->slice($payload, $node['type'] ?? null) : null;

        return $resource
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            // The graph is rebuilt whenever the code changes and the source is
            // read from the working copy, so a cached page would answer for a
            // version of the codebase that no longer exists.
            ->setHeader('Cache-Control', 'no-store')
            ->setContent($this->renderer->renderNode(
                $payload->class,
                $node,
                $payload->from,
                $this->graph->isAvailable(),
                $slice,
                $payload->wantsClass(),
                $payload->method,
            ));
    }

    /**
     * Class scope, an explicit method, or the conventional entry method for
     * what the graph says this class is — in that order of precedence.
     */
    private function slice(TraceNodePayload $payload, ?string $nodeType): ?SourceSlice
    {
        if ($payload->wantsClass()) {
            return $this->source->slice($payload->class, null);
        }

        $candidates = (new EntryMethodCatalog())->candidates($payload->class, $nodeType);
        if ($payload->method !== '') {
            // The recorded method first; a trace link outlives a rename, so a
            // miss falls through to the convention rather than to the class.
            array_unshift($candidates, $payload->method);
        }

        return $this->source->sliceAny($payload->class, $candidates);
    }
}
