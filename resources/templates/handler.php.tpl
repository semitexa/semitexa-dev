<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[AsPayloadHandler(payload: {{payloadClass}}::class, resource: {{resourceClass}}::class)]
final class {{className}} implements TypedHandlerInterface
{
{{injections}}    public function handle({{payloadClass}} $payload, {{resourceClass}} $resource): {{resourceClass}}
    {
        return $resource;
    }
}
