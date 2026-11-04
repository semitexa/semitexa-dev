<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

final class SampleAliasing
{
    use SampleHelperTrait {
        helpPublicly as helpUnderAnotherName;
    }
}
