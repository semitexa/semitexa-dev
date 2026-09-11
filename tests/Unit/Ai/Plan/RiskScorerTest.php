<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Plan;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Plan\RiskScorer;
use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;
use Semitexa\Dev\Application\Service\Ai\Recipe\RecipeRegistry;

final class RiskScorerTest extends TestCase
{
    public function testModuleCreationDoesNotAdviseManualComposerAutoload(): void
    {
        $recipe = RecipeRegistry::find('add_module');
        self::assertInstanceOf(Recipe::class, $recipe);
        $assessment = (new RiskScorer())->score($recipe, [], 'Billing');
        self::assertStringNotContainsString('composer dump-autoload', implode(' ', $assessment->required_steps));
        self::assertStringContainsString('discovery handles autoloading', implode(' ', $assessment->required_steps));
    }

    public function testPackageAndLocalModuleBoundariesCannotCollide(): void
    {
        $recipe = RecipeRegistry::find('refactor_existing_code');
        self::assertInstanceOf(Recipe::class, $recipe);
        $assessment = (new RiskScorer())->score($recipe, [
            'packages/Billing/src/A.php',
            'packages/Billing/src/B.php',
            'src/modules/Billing/src/C.php',
        ]);
        self::assertStringContainsString('spans 2 modules', implode(' ', $assessment->reasons));
        self::assertContains('run integration tests for each touched module', $assessment->required_steps);
    }

    public function testTwoPackagesCountAsCrossModuleWork(): void
    {
        $recipe = RecipeRegistry::find('refactor_existing_code');
        self::assertInstanceOf(Recipe::class, $recipe);
        $assessment = (new RiskScorer())->score($recipe, [
            'packages/semitexa-core/src/A.php',
            'packages/semitexa-dev/src/B.php',
        ]);
        self::assertStringContainsString('spans 2 modules', implode(' ', $assessment->reasons));
    }

    public function testRenameSymbolGuidanceReferencesExistingContextCommand(): void
    {
        $recipe = null;
        foreach (RecipeRegistry::all() as $candidate) {
            if ($candidate->id === 'rename_symbol') {
                $recipe = $candidate;
                break;
            }
        }

        self::assertInstanceOf(Recipe::class, $recipe);

        $assessment = (new RiskScorer())->score($recipe);

        self::assertContains(
            'run ai:context rename_symbol to inspect prior art and callers before renaming',
            $assessment->required_steps,
        );
    }
}
