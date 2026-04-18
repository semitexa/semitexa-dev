<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Plan;

use Semitexa\Dev\Ai\Recipe\Recipe;

/**
 * Translates a recipe + a (possibly empty) change set into a risk level.
 *
 * Phase 2 implementation uses cheap, deterministic signals:
 *   - Recipe's `default_risk` is the floor.
 *   - Multiple modules touched → +1 step.
 *   - Files under `packages/` (published surface) → +1 step.
 *   - Bench/test fixtures untouched while production is changing → reminder
 *     to add tests, but no risk bump on its own.
 *
 * Returns a {@see RiskAssessment} with `level ∈ {low, medium, high}` plus the
 * reasoning so the planner can surface "why."
 */
final class RiskScorer
{
    private const LEVELS = ['low', 'medium', 'high'];

    /**
     * @param list<string> $changedFiles  Repo-relative paths the agent intends to touch.
     */
    public function score(Recipe $recipe, array $changedFiles = [], ?string $moduleHint = null): RiskAssessment
    {
        $baseIndex = array_search($recipe->default_risk, self::LEVELS, true);
        $levelIndex = $baseIndex === false ? 0 : $baseIndex;

        $reasons = ["recipe {$recipe->id} starts at {$recipe->default_risk}"];
        $requiredSteps = ['run ai:verify after edits to confirm lint passes'];

        $modulesTouched = $this->modulesIn($changedFiles);
        if (count($modulesTouched) > 1) {
            $levelIndex = min($levelIndex + 1, 2);
            $reasons[] = 'cross-module change spans ' . count($modulesTouched) . ' modules: ' . implode(',', $modulesTouched);
            $requiredSteps[] = 'run integration tests for each touched module';
        }

        $publishedHits = array_values(array_filter(
            $changedFiles,
            static fn(string $p): bool => str_starts_with($p, 'packages/'),
        ));
        if ($publishedHits !== []) {
            $levelIndex = min($levelIndex + 1, 2);
            $reasons[] = 'published surface affected: ' . implode(',', array_slice($publishedHits, 0, 3));
            $requiredSteps[] = 'consider semver bump for affected packages';
        }

        if ($moduleHint !== null && in_array($recipe->id, ['add_module'], true)) {
            $reasons[] = "creating new module {$moduleHint}";
            $requiredSteps[] = 'register module in composer autoload + run composer dump-autoload';
        }

        if ($recipe->id === 'rename_symbol') {
            $requiredSteps[] = 'use ai:review-graph:impact to enumerate callers before renaming';
        }

        return new RiskAssessment(
            level: self::LEVELS[$levelIndex],
            score: $levelIndex,
            reasons: array_values(array_unique($reasons)),
            required_steps: array_values(array_unique($requiredSteps)),
        );
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function modulesIn(array $files): array
    {
        $modules = [];
        foreach ($files as $f) {
            if (preg_match('#^src/modules/([^/]+)/#', $f, $m) === 1) {
                $modules[$m[1]] = true;
            }
        }
        return array_keys($modules);
    }
}
