<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * `/__explorer/openapi.json`: an OpenAPI 3.1 document for every API, stream
 * and GraphQL route, to import into Postman or Insomnia. `?pages=1` adds the
 * HTML pages; `?download=1` serves it as a file.
 *
 * Public for the same reason `/__observatory` is: the route only exists where
 * semitexa/dev is installed, and the handler answers 404 to anyone the
 * ObservatoryPanelGate refuses.
 */
#[AsPublicPayload(
    path: '/__explorer/openapi.json',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ExplorerOpenApiPayload
{
    private bool $pages = false;
    private bool $download = false;

    public function setPages(string $pages): void
    {
        $this->pages = $pages === '1' || $pages === 'true';
    }

    public function setDownload(string $download): void
    {
        $this->download = $download === '1' || $download === 'true';
    }

    public function withPages(): bool
    {
        return $this->pages;
    }

    public function asDownload(): bool
    {
        return $this->download;
    }
}
