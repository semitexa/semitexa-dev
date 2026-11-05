<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\SatisfiesRepositoryContract;

#[SatisfiesRepositoryContract(of: OrderStoreInterface::class)]
final class InheritingStore extends BaseStore implements OrderStoreInterface
{
}
