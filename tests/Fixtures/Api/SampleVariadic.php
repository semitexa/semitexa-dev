<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

final class SampleVariadic
{
    public function __construct(string ...$scopes) {}
}
