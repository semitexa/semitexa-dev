<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable;

/** Used to read OrderTable::class before the move. */
final class MentioningHandler
{
    public function note(): string
    {
        return 'see OrderTable::class';
    }
}
