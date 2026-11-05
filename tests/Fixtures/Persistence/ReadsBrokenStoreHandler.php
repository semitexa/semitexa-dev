<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;

final class ReadsBrokenStoreHandler
{
    #[InjectAsReadonly]
    protected BrokenParentStore $broken;

    #[InjectAsReadonly]
    protected OrderStoreInterface $orders;
}
