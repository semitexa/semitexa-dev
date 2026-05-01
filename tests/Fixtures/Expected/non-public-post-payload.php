<?php

declare(strict_types=1);

namespace Semitexa\Modules\Website\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPayload;
use Semitexa\Modules\Website\Application\Resource\Response\ContactFormResponse;

#[AsPayload(
    path: '/contact',
    methods: ['POST'],
    responseWith: ContactFormResponse::class,
)]
class ContactFormPayload
{
}
