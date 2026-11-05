<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Attribute\SatisfiesServiceContract;

#[SatisfiesServiceContract(of: BilledOrdersInterface::class)]
final class BilledOrders implements BilledOrdersInterface
{
    #[InjectAsReadonly]
    protected OrderStoreInterface $orders;
}
