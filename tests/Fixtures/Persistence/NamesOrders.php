<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

trait NamesOrders
{
    public function table(): string
    {
        return OrderTable::class;
    }
}
