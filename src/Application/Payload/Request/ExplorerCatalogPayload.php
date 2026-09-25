<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The route list behind the API Explorer, at `/__explorer/catalog`: every
 * route grouped by kind, or with `?id=<methods path>` one route together with
 * its field-level contract.
 *
 * Public for the same reason `/__observatory` is: the route only exists where
 * semitexa/dev is installed, and the handler answers 404 to anyone the
 * ObservatoryPanelGate refuses.
 */
#[AsPublicPayload(
    path: '/__explorer/catalog',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ExplorerCatalogPayload
{
    private string $id = '';

    public function setId(string $id): void
    {
        $this->id = trim($id);
    }

    public function getId(): string
    {
        return $this->id;
    }
}
