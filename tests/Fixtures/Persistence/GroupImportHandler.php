<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Dev\Tests\Fixtures\{Persistence\OrderTable as Orders, Persistence\Order};

final class GroupImportHandler
{
    public function table(): string
    {
        return Orders::class;
    }
}
