<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

/**
{{doc}}
 */
final class {{className}} extends TestCase
{
    #[Test]
    public function {{testMethod}}(): void
    {
{{body}}
    }
}
