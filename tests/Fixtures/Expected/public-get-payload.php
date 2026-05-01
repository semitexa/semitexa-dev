<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Payload\Request;

use Semitexa\Authorization\Attribute\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Modules\Website\Application\Resource\Response\PricingResponse;

#[AsPayload(
    path: '/pricing',
    methods: ['GET'],
    responseWith: PricingResponse::class,
)]
#[PublicEndpoint]
class PricingPayload
{
}
