<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Plan;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Plan\RiskScorer;
use Semitexa\Dev\Ai\Recipe\Recipe;
use Semitexa\Dev\Ai\Recipe\RecipeRegistry;

final class RiskScorerTest extends TestCase
{
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
