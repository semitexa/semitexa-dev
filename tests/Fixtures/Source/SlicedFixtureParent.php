<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Source;

abstract class SlicedFixtureParent
{
    public function inherited(): string
    {
        return 'FIXTURE_INHERITED_BODY';
    }
}
