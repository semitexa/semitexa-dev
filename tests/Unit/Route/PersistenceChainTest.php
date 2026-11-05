<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Route;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Route\PersistenceChain;
use Semitexa\Dev\Tests\Fixtures\Persistence\ChildOfBaseStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\DbOrderStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\Order;
use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable;
use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTableMapper;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlaceOrderHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlaceOrderThroughServiceHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlainHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\QueryingHandler;
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
    public function a_service_between_the_handler_and_the_interface_changes_nothing(): void
    {
        self::assertSame(DbOrderStore::class, $this->chain()->of([PlaceOrderThroughServiceHandler::class])[0]['repository'] ?? null);
    }

    #[Test]
    public function a_service_that_names_a_table_is_no_repository(): void
    {
        $chain = $this->chain()->of([QueryingHandler::class]);

        self::assertCount(1, $chain);
        self::assertSame(OrderTable::class, $chain[0]['resource']);
        self::assertNull($chain[0]['repository']);
    }

    #[Test]
    public function a_table_named_by_a_parent_class_counts(): void
    {
        self::assertSame(OrderTable::class, $this->chain()->of([ChildOfBaseStore::class])[0]['resource'] ?? null);
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
