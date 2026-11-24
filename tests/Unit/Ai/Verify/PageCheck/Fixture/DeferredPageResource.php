<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture;

use Semitexa\Core\Attribute\AsResource;

#[AsResource(handle: 'page_check_fixture_deferred', template: '@project-layouts-semitexa-demo/pages/deferred.html.twig')]
final class DeferredPageResource
{
}
