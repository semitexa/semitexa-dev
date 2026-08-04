<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\TraceNodePayload;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;
use Semitexa\Dev\Application\Service\Trace\TraceHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\TraceReader;

/**
 * Serves one graph node at `/__trace/node`.
 *
 * Answers 404 rather than 403 outside dev, for the same reason as
 * {@see TraceHandler}: a "forbidden" confirms the route exists.
 */
#[AsPayloadHandler(payload: TraceNodePayload::class, resource: ResourceResponse::class)]
final class TraceNodeHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected TraceReader $reader;

    #[InjectAsReadonly]
    protected TraceGraphReader $graph;

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

        return $resource
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            // The graph is rebuilt whenever the code changes, so a cached page
            // would answer for a version of the codebase that no longer exists.
            ->setHeader('Cache-Control', 'no-store')
            ->setContent($this->renderer->renderNode(
                $payload->class,
                $node,
                $payload->from,
                $this->graph->isAvailable(),
            ));
    }
}
