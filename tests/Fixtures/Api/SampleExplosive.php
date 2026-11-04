<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

/** Proves a default expression is printed, never evaluated. */
final class SampleExplosive
{
    public function __construct()
    {
        throw new \LogicException('a default value was evaluated');
    }
}
