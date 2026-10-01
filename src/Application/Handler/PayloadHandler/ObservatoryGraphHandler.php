<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Payload\Request\ObservatoryGraphPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;
use Semitexa\ProjectGraph\Application\Service\Query\GraphBrowser;

/**
 * Serves {@see ObservatoryGraphPayload}. A refusal is the 404 every other
 * Observatory handler gives; a missing graph is a 503 that says how to build
 * one, so the view can tell "no graph yet" from "no such page".
 */
#[AsPayloadHandler(payload: ObservatoryGraphPayload::class, resource: ResourceResponse::class)]
final class ObservatoryGraphHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected TraceGraphReader $graph;

    public function handle(ObservatoryGraphPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allowsDevTools()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $storage = $this->graph->storage();
        if ($storage === null) {
            return $this->json($resource, [
                'error' => 'no-graph',
                'message' => 'No project graph was found. Build one with bin/semitexa ai:review-graph:generate.',
            ], HttpStatus::ServiceUnavailable->value);
        }

        $browser = new GraphBrowser($storage, ProjectRoot::get());

        $body = match ($payload->view) {
            'node' => $browser->describe($payload->id),
            'subgraph' => $browser->subgraph($payload->id, $payload->depth),
            'search' => ['query' => $payload->q, 'hits' => $browser->search($payload->q)],
            default => $browser->summary() + ['stale' => $this->graph->isStale()],
        };

        if ($body === null) {
            return $this->json($resource, ['error' => 'no-node', 'id' => $payload->id], HttpStatus::NotFound->value);
        }

        return $this->json($resource, $body, HttpStatus::Ok->value);
    }

    /** @param array<string, mixed> $body */
    private function json(ResourceResponse $resource, array $body, int $status): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            // The graph is rebuilt whenever the code changes.
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
