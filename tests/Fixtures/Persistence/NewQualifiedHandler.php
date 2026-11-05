<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

final class NewQualifiedHandler
{
    public function table(): object
    {
        return new \Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable();
    }
}
