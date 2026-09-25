<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The dev toolbar's script and stylesheet, served same-origin so a page's
 * `script-src 'self'` covers them without a nonce.
 */
#[AsPublicPayload(
    path: '/__toolbar/asset/{name}',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ToolbarAssetPayload
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
