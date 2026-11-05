<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

final class NewLocalHandler
{
    public function table(): object
    {
        return new OrderTable();
    }
}
