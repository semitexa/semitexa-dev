<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Persistence;

use Semitexa\Core\Attribute\InjectAsFactory;

final class FactoryHandler
{
    #[InjectAsFactory]
    protected FactoryOrderStoreInterface $stores;
}
