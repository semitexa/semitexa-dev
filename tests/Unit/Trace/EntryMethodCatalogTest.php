<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\EntryMethodCatalog;

final class EntryMethodCatalogTest extends TestCase
{
    #[Test]
    public function the_graph_type_wins_over_the_name(): void
    {
        // Named like a handler, typed as a gate: the type is what the graph
        // verified, the suffix is what somebody once chose.
        self::assertSame(
            ['gate', 'handle', '__invoke'],
            (new EntryMethodCatalog())->candidates('App\\Auth\\ThingHandler', 'auth_handler'),
        );
    }

    #[Test]
    public function handlers_enter_through_handle(): void
    {
        self::assertSame(['handle', '__invoke'], (new EntryMethodCatalog())->candidates('App\\X\\ThingHandler', 'handler'));
    }

    #[Test]
    public function the_suffix_answers_when_the_graph_does_not_know_the_class(): void
    {
        $catalog = new EntryMethodCatalog();

        self::assertSame(['handle', '__invoke'], $catalog->candidates('App\\X\\ThingHandler', null));
        self::assertSame(['gate', 'handle', '__invoke'], $catalog->candidates('App\\X\\PreHydrationAuthGate', null));
        self::assertSame(['execute', '__invoke'], $catalog->candidates('App\\X\\SyncCommand', null));
    }

    #[Test]
    public function a_resource_has_no_preferred_method(): void
    {
        self::assertSame([], (new EntryMethodCatalog())->candidates('App\\X\\ThingResource', 'resource'));
    }

    #[Test]
    public function an_unknown_shape_yields_nothing(): void
    {
        self::assertSame([], (new EntryMethodCatalog())->candidates('App\\X\\Whatever', null));
        self::assertSame([], (new EntryMethodCatalog())->candidates('App\\X\\Whatever', 'class'));
    }
}
