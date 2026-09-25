<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The API Explorer, at `/__explorer`: find any route by kind, then call it.
 * Its state lives in the URL hash, so `/__explorer#route=GET%20/about` opens
 * straight onto one route — which is how the Observatory dialog hands a route
 * over to a separate tab.
 *
 * Public for the same reason `/__observatory` is: the route only exists where
 * semitexa/dev is installed, and the handler answers 404 to anyone the
 * ObservatoryPanelGate refuses.
 */
#[AsPublicPayload(
    path: '/__explorer',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ExplorerPayload
{
}
