<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\RouteContractAssemblerInterface;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\DiscoveredRoute;

/**
 * Every route of the running app, grouped by {@see RouteKind} — the list the
 * API Explorer searches.
 *
 * Built from the same discovery the router uses, so it cannot list a route
 * that does not exist or miss one that does. The per-route contract (fields,
 * output) is the one `OPTIONS <route>` serves, assembled on demand by
 * {@see self::contract()} rather than for all routes up front.
 */
#[AsService]
final class RouteCatalog
{
    /** Methods every route answers implicitly — never what a developer calls it for. */
    private const IMPLICIT_METHODS = ['OPTIONS', 'HEAD'];

    #[InjectAsReadonly]
    protected AttributeDiscovery $discovery;

    #[InjectAsReadonly]
    protected RouteContractAssemblerInterface $contracts;

    /** @return list<CatalogEntry> sorted by kind, then path */
    public function entries(): array
    {
        /** @var list<array<string, mixed>> $routes */
        $routes = $this->discovery->getEnrichedRoutes();

        return self::fromRoutes($routes);
    }

    public function find(string $id): ?CatalogEntry
    {
        foreach ($this->entries() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * The route's field-level contract, as `OPTIONS <route>` serves it.
     *
     * @return array<string, mixed>|null null when the payload class cannot be assembled
     */
    public function contract(CatalogEntry $entry): ?array
    {
        if (!class_exists($entry->payload)) {
            return null;
        }

        try {
            return $this->contracts->assemble($entry->payload, $entry->response)->toDocument();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<array<string, mixed>> $routes raw discovered routes
     * @return list<CatalogEntry>
     */
    public static function fromRoutes(array $routes): array
    {
        $entries = [];
        foreach ($routes as $raw) {
            $route = DiscoveredRoute::fromArray($raw);
            if ($route->path === '' || $route->requestClass === '') {
                continue;
            }

            $methods = array_values(array_diff(array_map('strtoupper', $route->methods), self::IMPLICIT_METHODS));
            if ($methods === []) {
                continue;
            }

            $id = implode(',', $methods) . ' ' . $route->path;
            if (isset($entries[$id])) {
                continue;
            }

            preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)[^}]*\}/', $route->path, $params);
            $handler = $route->handlers[0]['class'] ?? null;

            $entries[$id] = new CatalogEntry(
                id: $id,
                kind: RouteKindClassifier::classify($raw),
                path: $route->path,
                methods: $methods,
                name: $route->name,
                module: $route->module,
                access: $route->accessType->value,
                payload: $route->requestClass,
                handler: is_string($handler) ? $handler : null,
                response: $route->responseClass,
                transport: RouteKindClassifier::transport($raw['transport'] ?? null),
                profiles: RouteKindClassifier::profiles($raw['renderProfile'] ?? null),
                pathParams: $params[1],
                requirements: $route->requirements,
            );
        }

        $entries = array_values($entries);
        $order = array_flip(array_map(static fn (RouteKind $k): string => $k->value, RouteKind::cases()));
        usort($entries, static fn (CatalogEntry $a, CatalogEntry $b): int
            => [$order[$a->kind->value], $a->path, $a->id] <=> [$order[$b->kind->value], $b->path, $b->id]);

        return $entries;
    }
}
