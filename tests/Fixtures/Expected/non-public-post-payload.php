<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Payload\Request;

use Semitexa\Core\Attributes\AsPayload;
use Semitexa\Core\Contract\ValidatablePayload;
use Semitexa\Core\Http\PayloadValidationResult;
use Semitexa\Modules\Website\Application\Resource\Response\ContactFormResponse;

#[AsPayload(
    path: '/contact',
    methods: ['POST'],
    responseWith: ContactFormResponse::class,
)]
class ContactFormPayload implements ValidatablePayload
{
    public function validate(): PayloadValidationResult
    {
        return new PayloadValidationResult(true, []);
    }
}
