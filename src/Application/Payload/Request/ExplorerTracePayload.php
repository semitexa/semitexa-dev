<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * `/__explorer/trace?token=explorer-…`: which trace file a request the
 * Explorer sent produced, so the result can link to what it did.
 */
#[AsPublicPayload(
    path: '/__explorer/trace',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ExplorerTracePayload
{
    private string $token = '';

    public function setToken(string $token): void
    {
        $this->token = trim($token);
    }

    public function getToken(): string
    {
        return $this->token;
    }
}
