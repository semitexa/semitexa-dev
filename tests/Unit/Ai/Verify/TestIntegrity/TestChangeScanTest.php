<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\TestIntegrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeFinding;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\TestChangeScan;

final class TestChangeScanTest extends TestCase
{
    private const BEFORE = <<<'PHP'
        <?php
        final class PriceTest extends TestCase
        {
            public function test_total(): void
            {
                self::assertSame(1999, $cart->total());
                self::assertCount(2, $cart->lines());
            }

            public function test_currency(): void
            {
                self::assertSame('EUR', $cart->currency());
            }
        }
        PHP;

    #[Test]
    public function a_value_check_loosened_to_a_shape_check_is_reported(): void
    {
        $after = str_replace("self::assertSame(1999, \$cart->total());", "self::assertNotNull(\$cart->total());", self::BEFORE);

        self::assertSame(
            [[TestChangeFinding::ASSERTION_WEAKENED, 'test_total', 'test_total(): 1 value check(s) replaced by weaker ones (value checks 2 → 1, assertions still 2)']],
            self::summary($this->scan(['tests/PriceTest.php' => [self::BEFORE, $after]])['findings']),
        );
    }

    #[Test]
    public function a_removed_test_and_a_removed_assertion_are_reported(): void
    {
        $after = <<<'PHP'
            <?php
            final class PriceTest extends TestCase
            {
                public function test_total(): void
                {
                    self::assertSame(1999, $cart->total());
                }
            }
            PHP;

        self::assertSame([
            [TestChangeFinding::ASSERTIONS_REMOVED, 'test_total', 'test_total(): 1 of 2 assertion(s) removed'],
            [TestChangeFinding::TEST_REMOVED, 'test_currency', 'test_currency() removed with 1 assertion(s)'],
        ], self::summary($this->scan(['tests/PriceTest.php' => [self::BEFORE, $after]])['findings']));
    }

    #[Test]
    public function a_rename_or_a_reshuffle_that_keeps_every_check_is_not_reported(): void
    {
        // Measured: judged per method, review-round reshuffles were most of
        // what fired; the file as a whole lost nothing.
        $renamed = str_replace('test_currency', 'the_currency_is_euro', self::BEFORE);
        $merged = <<<'PHP'
            <?php
            final class PriceTest extends TestCase
            {
                public function test_cart(): void
                {
                    self::assertSame(1999, $cart->total());
                    self::assertCount(2, $cart->lines());
                    self::assertSame('EUR', $cart->currency());
                }
            }
            PHP;

        self::assertSame([], $this->scan(['tests/PriceTest.php' => [self::BEFORE, $renamed]])['findings']);
        self::assertSame([], $this->scan(['tests/PriceTest.php' => [self::BEFORE, $merged]])['findings']);
    }

    #[Test]
    public function a_skip_and_a_removed_file_are_reported(): void
    {
        // Spelled out at run time, or the tests.skip-calls metric counts this fixture as a skip.
        $skipped = str_replace("self::assertSame('EUR', \$cart->currency());", 'self::mark' . "TestSkipped('flaky');", self::BEFORE);

        self::assertSame(
            [[TestChangeFinding::ASSERTIONS_REMOVED, 'test_currency', 'test_currency(): 1 of 1 assertion(s) removed'], [TestChangeFinding::SKIP_ADDED, 'test_currency', 'test_currency(): now skips (markTestSkipped/markTestIncomplete added)']],
            self::summary($this->scan(['tests/PriceTest.php' => [self::BEFORE, $skipped]])['findings']),
        );
        self::assertSame(
            [[TestChangeFinding::FILE_REMOVED, null, 'test file removed with 3 assertion(s) in 2 method(s)']],
            self::summary($this->scan(['tests/PriceTest.php' => [self::BEFORE, null]])['findings']),
        );
    }

    #[Test]
    public function a_reason_written_by_the_change_accepts_the_finding(): void
    {
        $after = str_replace(
            "self::assertSame('EUR', \$cart->currency());",
            "// verify:accept-test-change currency moved to MoneyTest with the Money type",
            self::BEFORE,
        );

        $result = $this->scan(['tests/PriceTest.php' => [self::BEFORE, $after]]);
        self::assertSame([], $result['findings']);
        self::assertSame('currency moved to MoneyTest with the Money type', $result['accepted'][0]->acceptedReason);

        // A reason too short to say anything is not one.
        $terse = str_replace('currency moved to MoneyTest with the Money type', 'moved', $after);
        self::assertCount(1, $this->scan(['tests/PriceTest.php' => [self::BEFORE, $terse]])['findings']);
    }

    #[Test]
    public function a_marker_already_committed_does_not_accept_a_new_weakening(): void
    {
        $before = str_replace("public function test_currency", "// verify:accept-test-change an old decision about something else\n    public function test_currency", self::BEFORE);
        $after = str_replace("self::assertSame('EUR', \$cart->currency());", '', $before);

        self::assertCount(1, $this->scan(['tests/PriceTest.php' => [$before, $after]])['findings']);
    }

    #[Test]
    public function a_deleted_test_file_takes_its_reason_from_any_file_of_the_change(): void
    {
        $result = $this->scan([
            'tests/PriceTest.php' => [self::BEFORE, null],
            'tests/MoneyTest.php' => [null, "<?php\n// verify:accept-test-change PriceTest folded into MoneyTest with the Money type\n"],
        ]);

        self::assertSame([], $result['findings']);
        self::assertCount(1, $result['accepted']);
    }

    #[Test]
    public function a_renamed_test_is_compared_with_its_own_old_content(): void
    {
        // Review of dev#127: renamed, the new path has no HEAD and read as new.
        $loosened = str_replace("self::assertSame('EUR', \$cart->currency());", '', self::BEFORE);
        $files = ['tests/PriceTest.php' => [self::BEFORE, null], 'tests/PriceCheck.php' => [null, $loosened]];

        self::assertSame(
            [[TestChangeFinding::ASSERTIONS_REMOVED, 'test_currency', 'test_currency(): 1 of 1 assertion(s) removed']],
            self::summary($this->scan($files, ['tests/PriceCheck.php' => 'tests/PriceTest.php'], ['tests/PriceCheck.php'])['findings']),
        );
        $moved = ['tests/PriceTest.php' => [self::BEFORE, null], 'tests/CartPriceTest.php' => [null, self::BEFORE]];
        self::assertSame([], $this->scan($moved, ['tests/CartPriceTest.php' => 'tests/PriceTest.php'], ['tests/CartPriceTest.php'])['findings']);
    }

    #[Test]
    public function the_marker_counts_only_in_a_comment(): void
    {
        // Review of dev#127: inside a string it is the test's data, not a reason.
        $after = str_replace(
            "self::assertSame('EUR', \$cart->currency());",
            "\$label = 'verify:accept-test-change and a reason that is long enough';",
            self::BEFORE,
        );

        self::assertCount(1, $this->scan(['tests/PriceTest.php' => [self::BEFORE, $after]])['findings']);
    }

    #[Test]
    public function a_test_whose_committed_version_cannot_be_read_fails_the_scan(): void
    {
        $scan = new TestChangeScan(
            static fn (string $path): ?string => throw new \RuntimeException("cannot read HEAD:{$path}"),
            static fn (string $path): string => self::BEFORE,
        );

        $this->expectExceptionObject(new \RuntimeException('cannot read HEAD:tests/PriceTest.php'));
        $scan->scan(['tests/PriceTest.php']);
    }

    /**
     * @param array<string, array{0: ?string, 1: ?string}> $files path => [committed, current]
     * @param array<string, string> $renamedFrom
     * @param list<string>|null $paths
     * @return array{findings: list<TestChangeFinding>, accepted: list<TestChangeFinding>}
     */
    private function scan(array $files, array $renamedFrom = [], ?array $paths = null): array
    {
        return (new TestChangeScan(
            static fn (string $path): ?string => $files[$path][0] ?? null,
            static fn (string $path): ?string => $files[$path][1] ?? null,
        ))->scan($paths ?? array_keys($files), $renamedFrom);
    }

    /**
     * @param list<TestChangeFinding> $findings
     * @return list<array{0: string, 1: ?string, 2: string}>
     */
    private static function summary(array $findings): array
    {
        return array_map(static fn (TestChangeFinding $f): array => [$f->code, $f->method, $f->message], $findings);
    }
}
