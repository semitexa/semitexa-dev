<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\Structure;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;

/**
 * Every module_structure code says why it exists, in the violation itself.
 * Same contract as the PHPStan rules' RATIONALE: a cause, then the incident
 * that taught it or the admission that there was none.
 */
final class ModuleStructureRationaleTest extends TestCase
{
    private const SHAPE = '/^Why: \S.{60,}(Learned (\d{4}-\d{2}|in )|no incident on record)/s';

    #[Test]
    public function every_code_has_a_rationale_of_the_agreed_shape(): void
    {
        $codes = [];
        foreach ((new ReflectionClass(ModuleStructureViolation::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'CODE_') && is_string($value)) {
                $codes[] = $value;
            }
        }
        self::assertCount(13, $codes, 'a code was added or removed: give it a rationale and update this count');

        $wrong = [];
        foreach ($codes as $code) {
            $why = ModuleStructureViolation::RATIONALES[$code] ?? '';
            if (preg_match(self::SHAPE, $why) !== 1) {
                $wrong[] = $code;
            }
        }

        self::assertSame([], $wrong);
        self::assertSame([], array_diff(array_keys(ModuleStructureViolation::RATIONALES), $codes), 'a rationale for a code that no longer exists');
    }

    #[Test]
    public function the_rationale_travels_with_the_violation(): void
    {
        $violation = new ModuleStructureViolation(
            code: ModuleStructureViolation::CODE_LOCAL_RULE_DIVERGENCE,
            module: 'semitexa-core',
            path: 'packages/semitexa-core/config/module-structure.php',
            message: 'Local rule diverges.',
            expected: 'same contract',
            actual: 'different contract',
            suggestedFix: 'Align both.',
        );
        $why = ModuleStructureViolation::RATIONALES[ModuleStructureViolation::CODE_LOCAL_RULE_DIVERGENCE];

        self::assertSame($why, $violation->toArray()['rationale']);
        self::assertSame(' — ' . $why, $violation->whyClause());
    }
}
