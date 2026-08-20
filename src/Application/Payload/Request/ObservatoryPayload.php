<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The live process panel, at `/__observatory`.
 *
 * Public for the same reason `/__trace` is: it only exists where semitexa/dev
 * is installed, and the handler answers 404 to anyone the ObservatoryPanelGate
 * refuses — everyone outside dev, unless monitor mode is on AND the caller
 * holds the token or comes in over a direct loopback tunnel.
 */
#[AsPublicPayload(
    path: '/__observatory',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryPayload
{
}
