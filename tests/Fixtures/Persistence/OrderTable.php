<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Orm\Attribute\FromTable;

#[FromTable(name: 'chain_orders')]
final readonly class OrderTable
{
}
