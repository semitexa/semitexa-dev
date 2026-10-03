<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Tenancy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Tenancy\LiveTenancyViolation;

/** Both live_tenancy codes say why they exist, in the violation itself. */
final class LiveTenancyRationaleTest extends TestCase
{
    #[Test]
    public function both_codes_carry_a_rationale_of_the_agreed_shape(): void
    {
        self::assertSame([LiveTenancyViolation::CODE_UNTENANTED, LiveTenancyViolation::CODE_UNBACKED], array_keys(LiveTenancyViolation::RATIONALES));
        foreach (LiveTenancyViolation::RATIONALES as $code => $why) {
            self::assertMatchesRegularExpression('/^Why: \S.{60,}(Learned \d{4}-\d{2}|no incident on record)/s', $why, $code);
        }
    }

    #[Test]
    public function the_rationale_travels_with_the_violation(): void
    {
        $violation = new LiveTenancyViolation(LiveTenancyViolation::CODE_UNTENANTED, 'orders', [self::class], 'App\\OrderResource');

        self::assertSame(LiveTenancyViolation::RATIONALES[LiveTenancyViolation::CODE_UNTENANTED], $violation->toArray()['rationale']);
    }
}
