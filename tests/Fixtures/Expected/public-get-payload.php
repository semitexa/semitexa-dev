<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Payload\Request;

use Semitexa\Authorization\Attributes\PublicEndpoint;
use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Modules\Website\Application\Resource\Response\PricingResponse;

#[AsPayload(
    path: '/pricing',
    methods: ['GET'],
    responseWith: PricingResponse::class,
)]
#[PublicEndpoint]
class PricingPayload implements ValidatablePayload
{
    public function validate(): PayloadValidationResult
    {
        return new PayloadValidationResult(true, []);
    }
}
