<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Source;

/**
 * Read by SourceSliceReaderTest through Reflection. The line numbers of
 * target() are asserted against Reflection at run time, not hard-coded, so
 * editing this file does not break the test — but keep the method bodies
 * distinctive, the assertions look for them.
 */
final class SlicedFixture extends SlicedFixtureParent
{
    public function target(): string
    {
        return 'FIXTURE_TARGET_BODY';
    }

    public function other(): string
    {
        return 'FIXTURE_OTHER_BODY';
    }
}
