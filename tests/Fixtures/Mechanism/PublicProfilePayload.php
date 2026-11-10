<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Mechanism;

use Semitexa\Core\Attribute\AsPublicPayload;

/** The Guard run's /guard/profile: public, no reason. Read by PublicPageReadsIdentityDetectorTest. */
#[AsPublicPayload(path: '/guard/profile', methods: ['GET'])]
final class PublicProfilePayload
{
}
