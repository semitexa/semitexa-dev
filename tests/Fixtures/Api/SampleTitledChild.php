<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

final class SampleTitledChild extends SampleTitled
{
    public function __construct(string $title)
    {
        parent::__construct(strtoupper($title));
    }
}
