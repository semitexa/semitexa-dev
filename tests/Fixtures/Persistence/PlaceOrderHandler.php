<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;

final class PlaceOrderHandler
{
    #[InjectAsReadonly]
    protected OrderStoreInterface $orders;
}
