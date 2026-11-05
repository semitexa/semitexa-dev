<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

final class OrderQuery
{
    public function table(): string
    {
        return \Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable::class;
    }
}
