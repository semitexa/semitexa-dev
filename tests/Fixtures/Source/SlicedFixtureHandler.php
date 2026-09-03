<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Source;

/**
 * Named like a handler so EntryMethodCatalog's suffix rule resolves handle()
 * for it without a graph. Read by AiObserveCommandTest.
 */
final class SlicedFixtureHandler
{
    public function handle(): string
    {
        return 'FIXTURE_HANDLER_BODY';
    }
}
