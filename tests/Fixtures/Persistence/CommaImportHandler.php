<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Dev\Tests\Fixtures\Persistence\Order, \Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable as Orders;

final class CommaImportHandler
{
    public function table(): string
    {
        return Orders::class;
    }
}
