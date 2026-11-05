<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Tagged
{
    public function __construct(public string $model)
    {
    }
}
