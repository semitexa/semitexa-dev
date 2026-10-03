<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\TestIntegrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\AssertionInventory;

final class AssertionInventoryTest extends TestCase
{
    #[Test]
    public function each_method_counts_its_checks_value_checks_and_skips(): void
    {
        $source = <<<'PHP'
            <?php
            final class FooTest extends TestCase
            {
                abstract protected function fixture(): array;

                public function test_values(): void
                {
                    $this->assertSame(1, $x);
                    self::assertCount(2, $list);
                    static::assertNotNull("{$y}");
                    array_map(function ($v) { $this->assertIsString($v); }, $list);
                    $this->expectException(\RuntimeException::class);
                }

                public function test_skipped(): void
                {
                    $this->SKIP('later');
                }

                private function helper(): string
                {
                    return assertSame(1, 2); // a function, not a call on the test
                }
            }
            PHP;

        // The skip call is spelled out at run time: as source text it would be
        // counted by the tests.skip-calls quality metric as a real skip.
        $source = str_replace('SKIP', 'mark' . 'TestSkipped', $source);

        self::assertSame([
            'test_values'  => ['strong' => 3, 'total' => 5, 'skips' => 0],
            'test_skipped' => ['strong' => 0, 'total' => 0, 'skips' => 1],
            'helper'       => ['strong' => 0, 'total' => 0, 'skips' => 0],
        ], AssertionInventory::of($source));
    }

    #[Test]
    public function same_named_methods_in_two_classes_add_up(): void
    {
        // Review of dev#127: the second overwrote the first, hiding a loss in it.
        $source = "<?php\nfinal class A { public function test_it(): void { \$this->assertSame(1, 1); \$this->assertSame(2, 2); } }\n"
            . "final class B { public function test_it(): void { \$this->assertNotNull(1); } }\n";

        self::assertSame(['test_it' => ['strong' => 2, 'total' => 3, 'skips' => 0]], AssertionInventory::of($source));
    }
}
