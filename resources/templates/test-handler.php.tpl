<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

/**
 * What {{handlerClass}} does with a request: as generated, it answers with the
 * resource it is given. When handle() starts filling the resource, assert what
 * it puts there, e.g. `$resource->getRenderContext()['…']`.
 *
 * Property injection (#[InjectAsReadonly]) is applied by the container at
 * runtime; a handler that gains injected services needs them set here.
 */
final class {{className}} extends TestCase
{
    #[Test]
    public function {{testMethod}}(): void
    {
        $handler = new {{handlerClass}}();
        $resource = new {{resourceClass}}();

        self::assertInstanceOf(TypedHandlerInterface::class, $handler);
        self::assertSame($resource, $handler->handle(new {{payloadClass}}(), $resource), 'the handler answers with the resource it was given');
    }
}
