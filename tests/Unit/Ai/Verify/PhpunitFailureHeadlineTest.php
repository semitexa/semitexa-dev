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
    public function a_data_provider_failure_keeps_its_data_set(): void
    {
        $output = "1) Tests\\Unit\\SumTest::testAdd with data set #3 (1, 1, 3)\nFailed for the third set\nFailed asserting that 2 is identical to 3.\n";

        self::assertSame('SumTest::testAdd with data set #3 (1, 1, 3) — Failed for the third set · ', PhpunitFailureHeadline::of($output));
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

    #[Test]
    public function a_guard_that_explains_itself_ends_the_headline_with_its_rationale(): void
    {
        // A ratchet failing used to say what grew and never why the ratchet is
        // there; the reason lived in a docblock the agent was not reading.
        $dir = sys_get_temp_dir() . '/headline-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/BudgetGuardTest.php', "<?php\nfinal class BudgetGuardTest\n{\n    private const RATIONALE = 'Why: nothing was counting. Learned 2026-09-06: it\\'s 102 methods.';\n}\n");
        $output = "There was 1 failure:\n\n1) Ns\\Structure\\BudgetGuardTest::nothing_grew\nClasses grew.\nFailed asserting that two arrays are identical.\n\n{$dir}/Helper.php:9\n{$dir}/BudgetGuardTest.php:41\n\nFAILURES!\n";

        try {
            self::assertSame(
                "BudgetGuardTest::nothing_grew — Classes grew. — Why: nothing was counting. Learned 2026-09-06: it's 102 methods. · ",
                PhpunitFailureHeadline::of($output),
            );
        } finally {
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    #[Test]
    public function every_guard_in_the_ratchet_suite_explains_itself(): void
    {
        $files = glob(__DIR__ . '/../../Structure/*Test.php') ?: [];
        self::assertGreaterThanOrEqual(5, count($files), 'the ratchet suite moved; this test would be vacuous');

        $wrong = [];
        foreach ($files as $file) {
            if (preg_match('/^Why: \S.{60,}(Learned (\d{4}-\d{2}|in )|no incident on record)/s', PhpunitFailureHeadline::rationaleIn($file)) !== 1) {
                $wrong[] = basename($file);
            }
        }

        self::assertSame([], $wrong, 'a guard without a RATIONALE (a single-quoted literal: "Why: ... Learned <date>: ..." or "... no incident on record")');
    }
}
