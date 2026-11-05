<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Orm\Attribute\AsMapper;

#[AsMapper(resourceModel: OrderTable::class)]
final class BrokenMapper
{
}
