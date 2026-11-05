<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;

final class OrderService
{
    #[InjectAsReadonly]
    protected OrderStoreInterface $orders;
}
