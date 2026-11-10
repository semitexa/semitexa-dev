<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Mechanism;

use Semitexa\Core\Attribute\AsPublicPayload;

/** A public page that says why it reads identity. Read by PublicPageReadsIdentityDetectorTest. */
#[AsPublicPayload(path: '/sign-in', methods: ['GET'], reason: 'sign-in page: greets a returning visitor')]
final class PublicSignInPayload
{
}
