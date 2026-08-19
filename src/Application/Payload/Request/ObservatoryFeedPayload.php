<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The JSON feed behind the live panel — the same snapshot `ai:observe ps`
 * consumes, served over HTTP for the polling page at `/__observatory`.
 */
#[AsPublicPayload(
    path: '/__observatory/feed',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryFeedPayload
{
}
