<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\SpanTarget;

/**
 * One rule for "what code did this span run", shared by the HTML viewer and
 * `ai:observe show --source`.
 */
final class SpanTargetTest extends TestCase
{
    #[Test]
    public function the_preferred_key_wins_over_other_classes_in_the_context(): void
    {
        $target = SpanTarget::of([
            'event' => 'App\\Event\\Thing',
            'listener' => 'App\\Listener\\OnThing',
            'method' => 'handle',
        ]);

        self::assertNotNull($target);
        self::assertSame('App\\Listener\\OnThing', $target->class);
        self::assertSame('handle', $target->method);
        self::assertSame('App\\Listener\\OnThing::handle', $target->key());
    }

    #[Test]
    public function any_class_shaped_value_counts_when_no_preferred_key_is_present(): void
    {
        $target = SpanTarget::of(['class' => 'App\\Exception\\Boom']);

        self::assertNotNull($target);
        self::assertSame('App\\Exception\\Boom', $target->class);
        self::assertNull($target->method);
        self::assertSame('App\\Exception\\Boom', $target->key());
    }

    #[Test]
    public function an_http_verb_is_not_a_method(): void
    {
        $target = SpanTarget::of(['method' => 'GET', 'gate' => 'App\\Auth\\Gate']);

        self::assertNotNull($target);
        self::assertNull($target->method);
    }

    #[Test]
    public function a_malformed_method_is_dropped_not_the_target(): void
    {
        $target = SpanTarget::of(['handler' => 'App\\H', 'method' => 'no spaces()']);

        self::assertNotNull($target);
        self::assertNull($target->method);
    }

    #[Test]
    public function no_class_no_target(): void
    {
        self::assertNull(SpanTarget::of(['path' => '/x', 'method' => 'GET', 'route' => 'home']));
        self::assertNull(SpanTarget::of([]));
        self::assertNull(SpanTarget::of(['handler' => 'NoNamespace']));
    }
}
