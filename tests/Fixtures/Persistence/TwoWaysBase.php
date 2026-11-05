<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;

abstract class TwoWaysBase
{
    #[InjectAsReadonly]
    private ThirdHop $next;
}
