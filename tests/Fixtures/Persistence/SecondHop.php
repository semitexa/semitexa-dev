<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsReadonly;

final class SecondHop
{
    #[InjectAsReadonly]
    protected ThirdHop $next;
}
