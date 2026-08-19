<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The live process panel, at `/__observatory`.
 *
 * Public for the same reason `/__trace` is: it only exists where semitexa/dev
 * is installed, and the handler answers 404 outside dev — this is a page that
 * should not exist in production, not one to secure there.
 */
#[AsPublicPayload(
    path: '/__observatory',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryPayload
{
}
