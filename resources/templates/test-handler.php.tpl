<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

/**
 * Smoke test for {{handlerClass}}.
 *
 * Asserts the handler instantiates and honours the typed-handler contract.
 * Property injection (#[InjectAsReadonly]) is applied by the container at
 * runtime, so a bare `new` is enough for this smoke. Replace the TODO with a
 * real assertion that exercises handle() with a populated payload + resource.
 */
final class {{className}} extends TestCase
{
    #[Test]
    public function {{testMethod}}(): void
    {
        $handler = new {{handlerClass}}();

        self::assertInstanceOf(TypedHandlerInterface::class, $handler);

        // TODO: exercise behaviour, e.g.
        // $resource = $handler->handle(new {{payloadClass}}(), new {{resourceClass}}());
        // self::assertSame('…', $resource->getRenderContext()['…']);
    }
}
