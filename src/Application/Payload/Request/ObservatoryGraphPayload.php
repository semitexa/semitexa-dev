<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The project graph, sliced for the Observatory's Graph view.
 *
 * One path, seven views: `summary` (what the view opens on), `node` (one node
 * and its edges), `subgraph` (a walk from a root, for tree expansion and the
 * DAG focus), `path` (entry point down to a node, to reveal it in the tree),
 * `search`, `findings` (unused classes and loops, as the CLI reports them)
 * and `traces` (recent recorded traces that ran the node's class). Dev only, like the Explorer: the graph is a map of
 * the application's internals.
 *
 * GET only and side-effect free — the hydrator binds query parameters on every
 * method, so a GET that wrote anything would write on a crafted link.
 */
#[AsPublicPayload(
    path: '/__observatory/graph',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryGraphPayload
{
    public const VIEWS = ['summary', 'node', 'subgraph', 'path', 'search', 'findings', 'traces'];

    public string $view = 'summary';

    /** A graph node id (`class:App\Foo`, `route:GET:/x`). Only ever bound into SQL. */
    public string $id = '';

    public int $depth = 1;

    public string $q = '';

    /**
     * The hydrator fills payloads through set{CamelCase}() rather than by writing
     * properties, so a public property alone is never populated.
     */
    public function setView(mixed $value): void
    {
        $this->view = is_string($value) && in_array($value, self::VIEWS, true) ? $value : 'summary';
    }

    public function setId(mixed $value): void
    {
        $this->id = is_string($value) ? substr($value, 0, 1024) : '';
    }

    public function setDepth(mixed $value): void
    {
        $this->depth = is_numeric($value) ? (int) $value : 1;
    }

    public function setQ(mixed $value): void
    {
        $this->q = is_string($value) ? substr($value, 0, 200) : '';
    }
}
