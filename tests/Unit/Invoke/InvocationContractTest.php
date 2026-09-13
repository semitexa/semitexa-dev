<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Invoke;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Invoke\InvocationContract;

/**
 * `ai:invoke` calls a handler for real. It writes what that handler writes,
 * sends what it sends, and charges what it charges — it just skips everything
 * the pipeline would have done first.
 *
 * Two things follow. It must not be runnable outside dev, where "skip auth and
 * run this handler" is a remote-execution surface rather than a feedback loop.
 * And the envelope has to SAY what was skipped: a result produced with no
 * authentication, no validation and no tenant reads exactly like one produced
 * with all three, and a reader who cannot tell will trust it as if it were.
 */
final class InvocationContractTest extends TestCase
{
    private ?string $previousEnv = null;

    protected function setUp(): void
    {
        $this->previousEnv = getenv('APP_ENV') === false ? null : (string) getenv('APP_ENV');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnv === null) {
            putenv('APP_ENV');
        } else {
            putenv('APP_ENV=' . $this->previousEnv);
        }
    }

    #[Test]
    public function execution_is_allowed_in_dev(): void
    {
        putenv('APP_ENV=dev');

        self::assertTrue(InvocationContract::executionAllowed());
    }

    #[Test]
    public function execution_is_refused_anywhere_else(): void
    {
        foreach (['prod', 'production', 'staging', 'test', ''] as $env) {
            putenv('APP_ENV=' . $env);
            self::assertFalse(
                InvocationContract::executionAllowed(),
                "APP_ENV={$env} must not be allowed to execute handlers"
            );
        }
    }

    #[Test]
    public function monitor_mode_does_not_buy_execution(): void
    {
        // Monitor mode opens read-only observability outside dev. Running a
        // handler is not reading.
        putenv('APP_ENV=prod');
        putenv('SEMITEXA_OBSERVATORY_MODE=monitor');

        try {
            self::assertFalse(InvocationContract::executionAllowed());
        } finally {
            putenv('SEMITEXA_OBSERVATORY_MODE');
        }
    }

    #[Test]
    public function the_refusal_says_what_would_have_happened(): void
    {
        putenv('APP_ENV=prod');

        $reason = InvocationContract::refusalReason();

        self::assertStringContainsString('APP_ENV', $reason);
        self::assertNotSame('', trim($reason));
    }

    #[Test]
    public function the_omissions_name_the_pipeline_and_the_context_separately(): void
    {
        $omitted = InvocationContract::omissions();

        self::assertArrayHasKey('pipeline', $omitted);
        self::assertArrayHasKey('context', $omitted);
        self::assertNotSame([], $omitted['pipeline']);
        self::assertNotSame([], $omitted['context']);
    }

    #[Test]
    public function the_omissions_name_the_things_a_reader_would_otherwise_assume(): void
    {
        $flat = strtolower(json_encode(InvocationContract::omissions(), JSON_THROW_ON_ERROR));

        foreach (['auth', 'validation', 'tenant', 'session', 'listener'] as $expected) {
            self::assertStringContainsString(
                $expected,
                $flat,
                "the envelope must state that {$expected} was not applied"
            );
        }
    }

    #[Test]
    public function the_omissions_are_a_flat_list_of_strings_a_machine_can_read(): void
    {
        foreach (InvocationContract::omissions() as $group => $entries) {
            self::assertIsString($group);
            foreach ($entries as $entry) {
                self::assertIsString($entry);
                self::assertNotSame('', trim($entry));
            }
        }
    }
}
