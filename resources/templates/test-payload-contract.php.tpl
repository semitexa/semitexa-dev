<?php

declare(strict_types=1);

namespace {{namespace}};

{{imports}}

/**
 * Contract test for {{payloadClass}}.
 *
 * Runs every strategy declared via #[TestablePayload] on the payload
 * (security, HTTP method, type enforcement, …) through the shared
 * semitexa-testing runtime. Add #[TestablePayload(strategies: [...])] to the
 * payload to opt into specific checks; without it the suite reports SKIP.
 */
final class {{className}} extends TestCase
{
    use TestsPayloads;

    #[Test]
    public function {{testMethod}}(): void
    {
        $this->assertPayloadContract({{payloadClass}}::class);
    }
}
