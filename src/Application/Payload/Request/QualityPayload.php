<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * `/__quality` — the quality ledger as a page. Dev only, like `/__trace`: the
 * handler answers 404 anywhere else.
 */
#[AsPublicPayload(
    path: '/__quality',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class QualityPayload
{
}
