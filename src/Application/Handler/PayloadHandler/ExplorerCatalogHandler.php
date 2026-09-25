<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ExplorerCatalogPayload;
use Semitexa\Dev\Application\Service\Explorer\CatalogEntry;
use Semitexa\Dev\Application\Service\Explorer\FieldHintReflector;
use Semitexa\Dev\Application\Service\Explorer\RouteCatalog;
use Semitexa\Dev\Application\Service\Explorer\RouteKind;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Serves `/__explorer/catalog`: the grouped route list, or one route with its
 * contract. 404 when the Observatory gate refuses, same as every dev panel.
 */
#[AsPayloadHandler(payload: ExplorerCatalogPayload::class, resource: ResourceResponse::class)]
final class ExplorerCatalogHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected RouteCatalog $catalog;

    public function handle(ExplorerCatalogPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $this->notFound($resource);
        }

        if ($payload->getId() !== '') {
            $entry = $this->catalog->find($payload->getId());
            if ($entry === null) {
                return $this->notFound($resource);
            }

            return $this->json($resource, [
                'route' => $entry->toArray(),
                'contract' => $this->catalog->contract($entry),
                'hints' => (object) FieldHintReflector::hints($entry->payload),
            ]);
        }

        $entries = $this->catalog->entries();
        $groups = [];
        foreach (RouteKind::cases() as $kind) {
            $groups[] = [
                'kind' => $kind->value,
                'label' => $kind->label(),
                'count' => count(array_filter($entries, static fn (CatalogEntry $e): bool => $e->kind === $kind)),
            ];
        }

        return $this->json($resource, [
            'groups' => $groups,
            'routes' => array_map(static fn (CatalogEntry $e): array => $e->toArray(), $entries),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function json(ResourceResponse $resource, array $body): ResourceResponse
    {
        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function notFound(ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setStatusCode(HttpStatus::NotFound->value)
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setContent('Not Found');
    }
}
