<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

#[AsEventListener(event: {{eventClass}}::class, execution: EventExecution::{{execution}})]
final class {{className}}
{
    public function handle({{eventClass}} $event): void
    {
    }
}
