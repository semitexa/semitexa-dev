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
use Semitexa\Dev\Application\Service\Trace\TraceClassIndex;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;
use Semitexa\ProjectGraph\Application\Service\Findings\TestCode;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Query\GraphBrowser;
use Semitexa\ProjectGraph\Application\Service\UiFlow\UiFlowView;

/**
 * Serves {@see ObservatoryGraphPayload}. A refusal is the 404 every other
 * Observatory handler gives; a missing graph is a 404 whose JSON body says
 * `no-graph` and how to build one, so the view can tell "no graph yet" from
 * "no such page". Not a 5xx: a project that never built a graph is a normal
 * state, not a server failure (the all-routes smoke rejects every 5xx). A
 * graph that exists but cannot be read IS a failure: 503 `graph-unreadable`.
 */
#[AsPayloadHandler(payload: ObservatoryGraphPayload::class, resource: ResourceResponse::class)]
final class ObservatoryGraphHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected TraceGraphReader $graph;

    #[InjectAsReadonly]
    protected TraceClassIndex $traces;

    public function handle(ObservatoryGraphPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allowsDevTools()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        if (!in_array($payload->view, ObservatoryGraphPayload::VIEWS, true)) {
            return $this->json($resource, ['error' => 'unknown-view', 'view' => $payload->view, 'views' => ObservatoryGraphPayload::VIEWS], HttpStatus::BadRequest->value);
        }

        if ($payload->view === 'traces') {
            // Read from the journal, not the graph: it answers without one.
            $fqcn = str_starts_with($payload->id, 'class:') ? substr($payload->id, 6) : '';

            return $this->json($resource, ['id' => $payload->id, 'traces' => $this->traces->forClass($fqcn)], HttpStatus::Ok->value);
        }

        $storage = $this->graph->storage();
        if ($storage === null) {
            return $this->graph->isMissing()
                ? $this->json($resource, [
                    'error' => 'no-graph',
                    'message' => 'No project graph was found. Build one with bin/semitexa ai:review-graph:generate.',
                ], HttpStatus::NotFound->value)
                : $this->json($resource, [
                    'error' => 'graph-unreadable',
                    'message' => 'A project graph exists but could not be read; the reason is in the dev log. Rebuilding with bin/semitexa ai:review-graph:generate --full replaces it.',
                ], HttpStatus::ServiceUnavailable->value);
        }

        $browser = new GraphBrowser($storage, ProjectRoot::get());

        $body = match ($payload->view) {
            'node' => $browser->describe($payload->id),
            'subgraph' => $browser->subgraph($payload->id, $payload->depth),
            // No such node is a 404 like `node` and `subgraph`; `path: null` means only "unreachable".
            'path' => $storage->nodes->findById($payload->id) === null ? null : ['id' => $payload->id, 'path' => $browser->pathToEntry($payload->id)],
            'search' => ['query' => $payload->q] + $browser->searchPage($payload->q),
            'findings' => $browser->findings(),
            // The list is read off the flow nodes; only the opened flow is traced (~1 s, all of them ~40 s).
            'flows' => ['flows' => $browser->flows()],
            'flow' => $browser->flow($payload->id),
            'ui' => $this->uiFlow($storage, $payload),
            default => $browser->summary() + ['stale' => $this->graph->isStale()],
        };

        if ($body === null) {
            return $this->json($resource, ['error' => 'no-node', 'id' => $payload->view === 'ui' && $payload->id === '' ? $payload->module : $payload->id], HttpStatus::NotFound->value);
        }

        return $this->json($resource, $body, HttpStatus::Ok->value);
    }

    /**
     * The UI flow, from the model `ai:ask ui-flow` reads: one screen (`id`),
     * one module (`module`), or the modules that have screens. A graph whose
     * project is not on this machine still answers 200: its `status` says
     * so, which is what the tab shows instead of an empty module list.
     *
     * @return array<string, mixed>|null null when no such screen or module
     */
    private function uiFlow(GraphStorage $storage, ObservatoryGraphPayload $payload): ?array
    {
        $view = new UiFlowView($storage, TestCode::of($storage));
        if ($payload->id !== '') {
            return $view->screen($payload->id);
        }

        return $payload->module !== '' ? $view->module($payload->module) : $view->overview();
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
