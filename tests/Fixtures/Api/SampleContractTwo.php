<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

final class SampleContractTwo implements \Countable, SampleContract
{
    public function run(): void {}

    public function count(): int
    {
        return 0;
    }
}
