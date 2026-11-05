<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Dev\Tests\Fixtures\Persistence as Res;

final class AliasPrefixHandler
{
    public function table(): string
    {
        return Res\OrderTable::class;
    }
}
