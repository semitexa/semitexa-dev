<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\PageCheck\Fixture;

use Semitexa\Ssr\Attribute\AsSlotResource;

#[AsSlotResource(
    handle: 'page_check_fixture_deferred',
    slot: 'page_check_fixture_search_filter',
    template: '@project-layouts-semitexa-demo/deferred/search-filter.html.twig',
    deferred: true,
    skeletonTemplate: '@project-layouts-semitexa-demo/deferred/search-filter.skeleton.html.twig',
)]
final class SearchFilterSlot
{
}
