<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;

final class BillOrdersHandler
{
    #[InjectAsReadonly]
    protected BilledOrdersInterface $billing;
}
