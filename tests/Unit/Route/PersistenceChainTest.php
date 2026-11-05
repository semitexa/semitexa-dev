<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Route;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Route\PersistenceChain;
use Semitexa\Dev\Tests\Fixtures\Persistence\DbOrderStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\Order;
use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable;
use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTableMapper;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlaceOrderHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlainHandler;
use Semitexa\Orm\Attribute\AsMapper;

final class PersistenceChainTest extends TestCase
{
    #[Test]
    public function a_handler_leads_through_the_interface_to_every_class_a_field_touches(): void
    {
        self::assertSame([[
            'table' => 'chain_orders',
            'resource' => OrderTable::class,
            'repository' => DbOrderStore::class,
            'mapper' => OrderTableMapper::class,
            'domain' => Order::class,
        ]], $this->chain()->of([PlaceOrderHandler::class]));
    }

    #[Test]
    public function a_route_that_stores_nothing_has_no_chain(): void
    {
        self::assertSame([], $this->chain()->of([PlainHandler::class]));
    }

    #[Test]
    public function a_class_that_does_not_exist_is_skipped(): void
    {
        self::assertSame([], $this->chain()->of(['Semitexa\\Dev\\Tests\\Fixtures\\Persistence\\Gone']));
    }

    private function chain(): PersistenceChain
    {
        $discovery = $this->createMock(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturnCallback(static fn (string $attribute): array => match ($attribute) {
            SatisfiesRepositoryContract::class => [DbOrderStore::class],
            AsMapper::class => [OrderTableMapper::class],
            default => [],
        });

        return new PersistenceChain($discovery);
    }
}
