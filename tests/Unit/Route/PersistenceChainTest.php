<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Route;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Route\PersistenceChain;
use Semitexa\Dev\Tests\Fixtures\Persistence\BilledOrders;
use Semitexa\Dev\Tests\Fixtures\Persistence\BillOrdersHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\BrokenMapper;
use Semitexa\Dev\Tests\Fixtures\Persistence\BrokenParentStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\ChildOfBaseStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\DbOrderStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\FirstHop;
use Semitexa\Dev\Tests\Fixtures\Persistence\InheritingStore;
use Semitexa\Dev\Tests\Fixtures\Persistence\MentioningHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\Order;
use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTable;
use Semitexa\Dev\Tests\Fixtures\Persistence\OrderTableMapper;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlaceOrderHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlaceOrderThroughServiceHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\PlainHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\QueryingHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\ReadsBrokenStoreHandler;
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
            'named_by' => DbOrderStore::class,
        ]], $this->tables([PlaceOrderHandler::class]));
    }

    #[Test]
    public function a_service_between_the_handler_and_the_interface_changes_nothing(): void
    {
        self::assertSame(DbOrderStore::class, $this->tables([PlaceOrderThroughServiceHandler::class])[0]['repository'] ?? null);
    }

    #[Test]
    public function a_service_that_names_a_table_is_no_repository(): void
    {
        $chain = $this->tables([QueryingHandler::class]);

        self::assertCount(1, $chain);
        self::assertSame(OrderTable::class, $chain[0]['resource']);
        self::assertNull($chain[0]['repository']);
    }

    #[Test]
    public function a_table_named_by_a_parent_class_counts(): void
    {
        self::assertSame(OrderTable::class, $this->tables([ChildOfBaseStore::class])[0]['resource'] ?? null);
    }

    #[Test]
    public function a_route_that_stores_nothing_has_no_chain(): void
    {
        self::assertSame([], $this->tables([PlainHandler::class]));
    }

    #[Test]
    public function a_class_that_does_not_exist_is_skipped(): void
    {
        self::assertSame([], $this->tables(['Semitexa\\Dev\\Tests\\Fixtures\\Persistence\\Gone']));
    }

    #[Test]
    public function a_service_contract_leads_on_to_the_repository_behind_it(): void
    {
        self::assertSame(DbOrderStore::class, $this->tables([BillOrdersHandler::class])[0]['repository'] ?? null);
    }

    #[Test]
    public function a_table_mentioned_only_in_a_comment_a_string_or_an_unused_import_is_not_named(): void
    {
        self::assertSame([], $this->tables([MentioningHandler::class]));
    }

    #[Test]
    public function a_repository_keeps_the_table_it_inherits(): void
    {
        self::assertSame(InheritingStore::class, $this->tables([InheritingStore::class])[0]['repository'] ?? null);
    }

    #[Test]
    public function a_parent_is_read_at_the_last_step_of_the_walk(): void
    {
        self::assertSame(OrderTable::class, $this->tables([FirstHop::class])[0]['resource'] ?? null);
    }

    #[Test]
    public function a_broken_mapper_costs_that_mapper_and_is_named(): void
    {
        $chain = $this->chain([BrokenMapper::class])->of([PlaceOrderHandler::class]);

        self::assertSame(OrderTable::class, $chain['tables'][0]['resource'] ?? null);
        self::assertSame([BrokenMapper::class], $chain['unreadable']);
    }

    #[Test]
    public function a_class_that_cannot_load_is_named_instead_of_failing_the_route(): void
    {
        $chain = $this->chain()->of([ReadsBrokenStoreHandler::class]);

        self::assertSame(OrderTable::class, $chain['tables'][0]['resource'] ?? null);
        self::assertSame([BrokenParentStore::class], $chain['unreadable']);
    }

    /**
     * @param list<string> $classes
     * @return list<array<string, ?string>>
     */
    private function tables(array $classes): array
    {
        return $this->chain()->of($classes)['tables'];
    }

    /** @param list<string> $extraMappers */
    private function chain(array $extraMappers = []): PersistenceChain
    {
        $discovery = $this->createMock(ClassDiscovery::class);
        $discovery->method('findClassesWithAttribute')->willReturnCallback(static fn (string $attribute): array => match ($attribute) {
            SatisfiesRepositoryContract::class => [DbOrderStore::class],
            SatisfiesServiceContract::class => [BilledOrders::class],
            AsMapper::class => [OrderTableMapper::class, ...$extraMappers],
            default => [],
        });

        return new PersistenceChain($discovery);
    }
}
