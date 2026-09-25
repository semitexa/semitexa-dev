<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The Explorer's stylesheet and script, served same-origin so a strict
 * Content-Security-Policy (`script-src 'self'`) covers them without a nonce —
 * the reasoning is the one ObservatoryAssetPayload gives.
 */
#[AsPublicPayload(
    path: '/__explorer/asset/{name}',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ExplorerAssetPayload
{
    private string $name = '';

    public function setName(string $name): void
    {
        $this->name = trim($name);
    }

    public function getName(): string
    {
        return $this->name;
    }
}
