<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryTopology;

/**
 * What the picture is allowed to name.
 *
 * The properties worth pinning are the ones that keep a box OFF the drawing,
 * because that is the direction the defect ran: three products were drawn
 * unconditionally, and on a default project none of the three is in use.
 *
 * Two cases are deliberately absent, and neither is an oversight:
 *
 * - A truly unset CACHE_DRIVER cannot be staged here. `putenv('CACHE_DRIVER')`
 *   clears the process variable, and Environment then falls through to the
 *   parsed .env, which sets it in this workspace. The empty-string case below
 *   exercises the same branch through a path the test can actually reach.
 * - `unavailable` needs a worker where neither NATS nor the database transport
 *   registered, and the registry is static and self-initialising. Forcing it
 *   would be testing the reset, not the behaviour.
 */
final class ObservatoryTopologyTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('CACHE_DRIVER');
        putenv('EVENTS_ASYNC');
        putenv('EVENTS_TRANSPORT');
    }

    #[Test]
    public function without_async_events_there_is_no_transport_to_name(): void
    {
        putenv('EVENTS_ASYNC=0');
        putenv('EVENTS_TRANSPORT');

        self::assertSame(
            'in-memory',
            (new ObservatoryTopology())->queueTransport(),
            'the default project has no asynchronous boundary at all',
        );
    }

    #[Test]
    public function the_transport_override_is_reported_as_it_stands(): void
    {
        putenv('EVENTS_ASYNC=1');
        putenv('EVENTS_TRANSPORT=database');

        self::assertSame('database', (new ObservatoryTopology())->queueTransport());
    }

    #[Test]
    public function an_empty_cache_driver_is_no_cache_driver(): void
    {
        putenv('CACHE_DRIVER=');

        self::assertNull(
            (new ObservatoryTopology())->cacheDriver(),
            'a blank value is an absent value, not a driver named ""',
        );
    }

    #[Test]
    public function the_cache_driver_is_reported_only_when_it_was_asked_for(): void
    {
        putenv('CACHE_DRIVER=  redis  ');

        self::assertSame(
            'redis',
            (new ObservatoryTopology())->cacheDriver(),
            'surrounding space in an .env line must not produce a driver nobody recognises',
        );
    }

    #[Test]
    public function this_package_does_not_restate_the_cache_default(): void
    {
        putenv('CACHE_DRIVER=array');

        self::assertSame(
            'array',
            (new ObservatoryTopology())->cacheDriver(),
            'the value is passed through; deciding what array MEANS belongs to the picture, not here',
        );
    }
}
