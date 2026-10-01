<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Semitexa\ProjectGraph\Application\Service\Graph\GraphStorage;
use Semitexa\ProjectGraph\Application\Service\Support\ProjectGraphConnection;
use Semitexa\ProjectGraph\Domain\Model\Node;

/**
 * Answers "why this class" from the project graph, at view time.
 *
 * A trace records what ran. It deliberately does not record why: the reason a
 * handler was reached is a property of the code, not of the request, and copying
 * it into every trace file would freeze an answer that goes stale the moment the
 * class changes. The graph already holds it — {@see ai:review-graph:generate}
 * extracts `handles`, `produces`, `serves_route`, `accepts` and `returns` edges —
 * so the viewer resolves the link on the way out instead.
 *
 * Read-only by construction. It opens the graph the same way the console commands
 * do, minus the schema sync those perform: a debug view must never write to, or
 * migrate, the database it is reading.
 */
#[AsService]
final class TraceGraphReader
{
    /**
     * `imports` is every `use` statement in the file — 22k edges of it, against
     * ~5k for the next kind. It buries the structural edges that carry meaning,
     * so it is dropped rather than ranked below them.
     */
    private const NOISE = ['imports', 'belongs_to_domain', 'intent_for'];

    /** Kinds worth surfacing first, in the order a reader wants them. */
    private const ORDER = [
        'handles', 'produces', 'serves_route', 'accepts', 'returns',
        'satisfies_contract', 'implements', 'extends', 'instantiates',
    ];

    /**
     * How long a failed open is remembered before the next request tries again.
     * Long enough that a missing graph costs one attempt per half-minute, not
     * one per request; short enough that building the graph while the stack is
     * up shows in the viewer without a worker restart.
     */
    private const RETRY_AFTER_SECONDS = 30;

    /** Staleness walks every indexed file; that answer is reused this long. */
    private const STALE_CHECK_TTL_SECONDS = 30;

    #[InjectAsReadonly]
    protected ConnectionRegistry $connections;

    /** Per-worker: opening the graph is a connection, not a per-request cost. */
    private ?GraphStorage $storage = null;

    /** When the last open failed; null while no open has failed. */
    private ?int $failedAt = null;

    /** The reason last logged, so a graph that stays missing is logged once. */
    private ?string $loggedReason = null;

    /** @var array{at: int, stale: bool}|null */
    private ?array $staleCheck = null;

    public function isAvailable(): bool
    {
        return $this->open() !== null;
    }

    /**
     * One class as the graph knows it, with the edges that explain its place.
     *
     * @return array{
     *     fqcn: string,
     *     name: string,
     *     type: string,
     *     module: string,
     *     file: string,
     *     line: int,
     *     endLine: int,
     *     stale: bool,
     *     out: list<array{kind: string, fqcn: string, name: string, type: string}>,
     *     in: list<array{kind: string, fqcn: string, name: string, type: string}>
     * }|null
     */
    public function describe(string $fqcn): ?array
    {
        $storage = $this->open();
        if ($storage === null) {
            return null;
        }

        $node = $storage->nodes->findByFqcn($fqcn);
        if ($node === null) {
            return null;
        }

        $out = [];
        $in = [];
        foreach ($storage->edges->findByNode($node->getId()) as $edge) {
            $kind = $edge->getType()->value;
            if (in_array($kind, self::NOISE, true)) {
                continue;
            }

            $isOutgoing = $edge->getSourceId() === $node->getId();
            $otherId = $isOutgoing ? $edge->getTargetId() : $edge->getSourceId();
            $other = $this->resolve($storage, $otherId);
            if ($other === null) {
                continue;
            }

            $row = [
                'kind' => $kind,
                'fqcn' => $other->getFqcn(),
                'name' => $other->name(),
                'type' => $other->getType()->value,
            ];

            if ($isOutgoing) {
                $out[] = $row;
            } else {
                $in[] = $row;
            }
        }

        return [
            'fqcn' => $node->getFqcn(),
            'name' => $node->name(),
            'type' => $node->getType()->value,
            'module' => $node->getModule(),
            'file' => $this->relative($node->getFile()),
            'line' => $node->getLine(),
            'endLine' => $node->getEndLine(),
            'stale' => $this->isStale(),
            'out' => $this->rank($out),
            'in' => $this->rank($in),
        ];
    }

    /**
     * An edge can point at a class the graph only knows by name — a placeholder
     * for something outside the scanned tree. Those are dropped rather than
     * rendered as dead links.
     */
    private function resolve(GraphStorage $storage, string $nodeId): ?Node
    {
        $node = $storage->nodes->findById($nodeId);

        return $node !== null && $node->getFqcn() !== '' ? $node : null;
    }

    /**
     * Whether code the graph indexed has changed since the graph was built.
     *
     * An indexed file that is newer than the last build, or gone, means the
     * edges shown may describe code that no longer exists. A file added since
     * the build is not seen here — the graph never knew it — which is the
     * "not in the graph" answer the viewer already gives for that class.
     */
    public function isStale(): bool
    {
        $now = time();
        if ($this->staleCheck !== null && $now - $this->staleCheck['at'] < self::STALE_CHECK_TTL_SECONDS) {
            return $this->staleCheck['stale'];
        }

        $stale = false;
        $storage = $this->open();
        if ($storage !== null) {
            try {
                $built = (int) ($storage->getMeta('last_update') ?? 0);
                foreach (array_keys($storage->fileIndex->getAll()) as $path) {
                    $mtime = @filemtime($path);
                    if ($mtime === false || $mtime > $built) {
                        $stale = true;
                        break;
                    }
                }
            } catch (\Throwable $e) {
                $this->logOnce('Project graph staleness check failed', $e);
            }
        }

        $this->staleCheck = ['at' => $now, 'stale' => $stale];

        return $stale;
    }

    /** The last build time, as the graph recorded it; null without a graph. */
    public function lastUpdate(): ?int
    {
        $value = $this->open()?->getMeta('last_update');

        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /** The open graph, for read-only views that query it directly. */
    public function storage(): ?GraphStorage
    {
        return $this->open();
    }

    /**
     * @param  list<array{kind: string, fqcn: string, name: string, type: string}> $edges
     * @return list<array{kind: string, fqcn: string, name: string, type: string}>
     */
    private function rank(array $edges): array
    {
        $seen = [];
        $unique = [];
        foreach ($edges as $edge) {
            $key = $edge['kind'] . '|' . $edge['fqcn'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $edge;
        }

        usort($unique, static function (array $a, array $b): int {
            $ra = array_search($a['kind'], self::ORDER, true);
            $rb = array_search($b['kind'], self::ORDER, true);
            $ra = $ra === false ? count(self::ORDER) : $ra;
            $rb = $rb === false ? count(self::ORDER) : $rb;

            return $ra === $rb ? strcmp($a['name'], $b['name']) : $ra <=> $rb;
        });

        return $unique;
    }

    /**
     * The graph stores the path the indexer saw, which is absolute and belongs to
     * whatever container built it. A developer wants the path they can open.
     */
    private function relative(string $file): string
    {
        $root = ProjectRoot::get();

        return str_starts_with($file, $root . '/')
            ? substr($file, strlen($root) + 1)
            : $file;
    }

    /**
     * Fails soft: a project that has never run `ai:review-graph:generate` has no
     * graph, and the trace viewer still has a job to do without one.
     *
     * A failure is not final. It used to be: the first request set a flag, and a
     * graph built a minute later stayed invisible until the worker restarted,
     * with nothing logged to say why. Now a failure is retried after a cooldown
     * and its reason logged once.
     */
    private function open(): ?GraphStorage
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        if ($this->failedAt !== null && time() - $this->failedAt < self::RETRY_AFTER_SECONDS) {
            return null;
        }

        try {
            $orm = ProjectGraphConnection::manager($this->connections, ProjectRoot::get());
            $storage = new GraphStorage(
                $orm->getAdapter(),
                $orm->getTransactionManager(),
                $orm->getMapperRegistry(),
                $orm->getResourceModelHydrator(),
                $orm->getResourceModelMetadataRegistry(),
                $orm->getResourceModelRelationLoader(),
                $orm->getAggregateWriteEngine(),
            );

            // Cheapest possible proof the tables exist: an unbuilt graph fails here
            // rather than on the first page a developer opens.
            $storage->nodes->countAll();

            $this->storage = $storage;
            $this->failedAt = null;
            $this->loggedReason = null;
        } catch (\Throwable $e) {
            $this->storage = null;
            $this->failedAt = time();
            $this->logOnce('Project graph unavailable to the trace viewer', $e);
        }

        return $this->storage;
    }

    private function logOnce(string $message, \Throwable $e): void
    {
        $reason = $e::class . ': ' . $e->getMessage();
        if ($reason === $this->loggedReason) {
            return;
        }

        $this->loggedReason = $reason;
        StaticLoggerBridge::info('dev', $message, ['reason' => $reason]);
    }
}
