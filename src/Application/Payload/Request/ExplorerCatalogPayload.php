<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The route list behind the API Explorer, at `/__explorer/catalog`: every
 * route grouped by kind, or with `?id=<methods path>` one route together with
 * its field-level contract. `?id=…&recorded=1` answers instead with the inputs
 * that route was really called with, from the recorded traces — asked
 * separately because reading traces is the slow part.
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
    private bool $recorded = false;

    public function setId(string $id): void
    {
        $this->id = trim($id);
    }

    public function setRecorded(string $recorded): void
    {
        $this->recorded = $recorded === '1' || $recorded === 'true';
    }

    public function wantsRecorded(): bool
    {
        return $this->recorded;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
