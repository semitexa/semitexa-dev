<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture;

use Semitexa\Core\Attribute\AsResource;

#[AsResource(handle: 'queue_page', template: '@project-layouts-semitexa-demo/platform/queue.html.twig')]
final class QueuePageResource
{
}
