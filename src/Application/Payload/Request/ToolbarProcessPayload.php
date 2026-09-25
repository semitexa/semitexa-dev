<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * What the dev toolbar shows about the request that rendered its page:
 * `/__toolbar/process/{id}` answers with that process's journal lines.
 *
 * Public for the same reason `/__observatory` is: the route only exists where
 * semitexa/dev is installed, and the handler answers 404 to anyone the
 * ObservatoryPanelGate refuses.
 */
#[AsPublicPayload(
    path: '/__toolbar/process/{id}',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ToolbarProcessPayload
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
