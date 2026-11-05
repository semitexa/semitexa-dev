<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Route;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Attribute\SatisfiesRepositoryContract;
use Semitexa\Core\Attribute\SatisfiesServiceContract;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Route\PersistenceChain;
use Semitexa\Dev\Tests\Fixtures\Persistence\AliasPrefixHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\BrokenAttributeHandler;
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
use Semitexa\Dev\Tests\Fixtures\Persistence\CommaImportHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\FactoryHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\GroupImportHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\MethodNamedLikeImportHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\NewLocalHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\NewQualifiedHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\ThirdHop;
use Semitexa\Dev\Tests\Fixtures\Persistence\TraitHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\TwoWaysHandler;
use Semitexa\Dev\Tests\Fixtures\Persistence\ZerothHop;
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
            'repositories' => [DbOrderStore::class],
            'mapper' => OrderTableMapper::class,
            'domain' => Order::class,
            'named_by' => DbOrderStore::class,
        ]], $this->tables([PlaceOrderHandler::class]));
    }

    #[Test]
    public function a_service_between_the_handler_and_the_interface_changes_nothing(): void
    {
        self::assertSame([DbOrderStore::class], $this->tables([PlaceOrderThroughServiceHandler::class])[0]['repositories'] ?? null);
    }

    #[Test]
    public function every_repository_behind_a_contract_is_listed(): void
    {
        $tables = $this->chain([], [], [InheritingStore::class])->of([PlaceOrderHandler::class])['tables'];

        self::assertCount(1, $tables);
        self::assertSame([DbOrderStore::class, InheritingStore::class], $tables[0]['repositories']);
    }

    #[Test]
    public function a_service_that_names_a_table_is_no_repository(): void
    {
        $chain = $this->tables([QueryingHandler::class]);

        self::assertCount(1, $chain);
        self::assertSame(OrderTable::class, $chain[0]['resource']);
        self::assertSame([], $chain[0]['repositories']);
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
    public function a_class_that_does_not_exist_is_named(): void
    {
        // verify:accept-test-change an empty answer with nothing said about why was the untruthful case
        $gone = 'Semitexa\\Dev\\Tests\\Fixtures\\Persistence\\Gone';
        $chain = $this->chain()->of([$gone]);

        self::assertSame([], $chain['tables']);
        self::assertSame([$gone], $chain['unreadable']);
    }

    #[Test]
    public function a_class_discovery_could_not_load_is_named(): void
    {
        $chain = $this->chain([], ['Semitexa\\Dev\\Tests\\Fixtures\\Persistence\\ImplementsGone' => 'its parent is gone'])->of([PlainHandler::class]);

        self::assertSame(['Semitexa\\Dev\\Tests\\Fixtures\\Persistence\\ImplementsGone'], $chain['unreadable']);
    }

    #[Test]
    public function the_walk_says_where_it_stopped(): void
    {
        self::assertSame([ThirdHop::class], $this->chain()->of([ZerothHop::class])['stopped_at']);
    }

    #[Test]
    public function an_attribute_that_cannot_be_read_is_named(): void
    {
        $chain = $this->chain()->of([BrokenAttributeHandler::class]);

        self::assertSame([BrokenAttributeHandler::class], $chain['unreadable']);
    }

    #[Test]
    public function a_shorter_way_to_a_class_wins_over_a_longer_one_found_first(): void
    {
        self::assertSame(OrderTable::class, $this->tables([TwoWaysHandler::class])[0]['resource'] ?? null);
    }

    #[Test]
    public function a_factory_leads_to_its_contract(): void
    {
        self::assertSame([DbOrderStore::class], $this->tables([FactoryHandler::class])[0]['repositories'] ?? null);
    }

    #[Test]
    public function every_way_of_writing_a_class_name_counts(): void
    {
        foreach ([GroupImportHandler::class, AliasPrefixHandler::class, NewLocalHandler::class, NewQualifiedHandler::class, CommaImportHandler::class] as $handler) {
            self::assertSame(OrderTable::class, $this->tables([$handler])[0]['resource'] ?? null, $handler);
        }
    }

    #[Test]
    public function a_table_named_in_a_trait_counts(): void
    {
        self::assertSame(OrderTable::class, $this->tables([TraitHandler::class])[0]['resource'] ?? null);
    }

    #[Test]
    public function a_method_named_like_an_import_is_no_use_of_it(): void
    {
        self::assertSame([], $this->tables([MethodNamedLikeImportHandler::class]));
    }

    #[Test]
    public function a_service_contract_leads_on_to_the_repository_behind_it(): void
    {
        self::assertSame([DbOrderStore::class], $this->tables([BillOrdersHandler::class])[0]['repositories'] ?? null);
    }

    #[Test]
    public function a_table_mentioned_only_in_a_comment_a_string_or_an_unused_import_is_not_named(): void
    {
        self::assertSame([], $this->tables([MentioningHandler::class]));
    }

    #[Test]
    public function a_repository_keeps_the_table_it_inherits(): void
    {
        self::assertSame([InheritingStore::class], $this->tables([InheritingStore::class])[0]['repositories'] ?? null);
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

    /**
     * @param list<string> $extraMappers
     * @param array<string, string> $unloadable
     * @param list<string> $extraRepositories
     */
    private function chain(array $extraMappers = [], array $unloadable = [], array $extraRepositories = []): PersistenceChain
    {
        $discovery = $this->createMock(ClassDiscovery::class);
        $discovery->method('unloadableClasses')->willReturn($unloadable);
        $discovery->method('findClassesWithAttribute')->willReturnCallback(static fn (string $attribute): array => match ($attribute) {
            SatisfiesRepositoryContract::class => [DbOrderStore::class, ...$extraRepositories],
            SatisfiesServiceContract::class => [BilledOrders::class],
            AsMapper::class => [OrderTableMapper::class, ...$extraMappers],
            default => [],
        });

        return new PersistenceChain($discovery);
    }
}
