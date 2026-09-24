<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\PhpunitFailureHeadline;

/**
 * A failing verification used to report PHPUnit's summary alone — "Tests: 20,
 * Failures: 1." — and throw away the one line that said what broke.
 */
final class PhpunitFailureHeadlineTest extends TestCase
{
    #[Test]
    public function the_first_failure_is_named_with_its_message(): void
    {
        $output = <<<'OUT'
            There was 1 failure:

            1) Semitexa\Dev\Tests\Unit\Structure\StructuralOutlierBudgetTest::no_recorded_outlier_has_grown
            These classes grew past their recorded size:
              - semitexa-ssr/src/HtmlResponse.php: 25 methods / 766 lines, budget 25 / 765
            Failed asserting that two arrays are identical.
            --- Expected

            FAILURES!
            Tests: 20, Assertions: 67, Failures: 1.
            OUT;

        self::assertSame(
            'StructuralOutlierBudgetTest::no_recorded_outlier_has_grown — These classes grew past their recorded size: - semitexa-ssr/src/HtmlResponse.php: 25 methods / 766 lines, budget 25 / 765 · ',
            PhpunitFailureHeadline::of($output),
        );
    }

    #[Test]
    public function a_passing_run_adds_nothing(): void
    {
        self::assertSame('', PhpunitFailureHeadline::of("OK (20 tests, 67 assertions)\n"));
    }

    #[Test]
    public function a_long_message_is_cut_on_a_character_not_a_byte(): void
    {
        $headline = PhpunitFailureHeadline::of("1) X::y\n" . str_repeat('бюджет ', 100) . "\n");

        self::assertTrue(mb_check_encoding($headline, 'UTF-8'));
        self::assertStringEndsWith('… · ', $headline);
    }
}
