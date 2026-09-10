<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/** The declared cron schedules, for the live panel's cron rows. Gated like the panel. */
#[AsPublicPayload(
    path: '/__observatory/schedules',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatorySchedulesPayload
{
}
