<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable;

final class MethodNamedLikeImportHandler
{
    public function note(object $o): mixed
    {
        return $o->OrderTable();
    }
}
