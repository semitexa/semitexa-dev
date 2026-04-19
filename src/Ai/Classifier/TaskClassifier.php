<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Classifier;

use Semitexa\Dev\Ai\Recipe\Recipe;
use Semitexa\Dev\Ai\Recipe\RecipeRegistry;

/**
 * Maps a one-line task description to the closest known {@see Recipe} using
 * a deterministic rule + keyword scorer. No ML, no embedding model — Phase 2
 * keeps this dumb-but-explainable so the agent can reason about why a recipe
 * was chosen.
 *
 * Scoring weights:
 *   verbs:    3 points per match (action verbs are the strongest signal)
 *   keywords: 2 points per match (domain nouns)
 *   bigrams:  1 point per shared 2-word substring with the recipe label
 *
 * The classifier never returns "no match"; the lowest-confidence recipe is
 * the catch-all so the agent always gets a starting point. Callers should
 * inspect {@see ClassificationResult::$score} to decide whether to trust it.
 */
final class TaskClassifier
{
    private const WEIGHT_VERB = 3;
    private const WEIGHT_KEYWORD = 2;
    private const WEIGHT_LABEL_BIGRAM = 1;
    private const NOISE_FLOOR = 2;

    public function classify(string $description, ?string $hintModule = null): ClassificationResult
    {
        $normalized = strtolower(trim($description));
        $tokens = $this->tokenize($normalized);
        $bigrams = $this->bigrams($tokens);

        $scored = [];
        foreach (RecipeRegistry::all() as $recipe) {
            $scored[] = [
                'recipe' => $recipe,
                'score'  => $this->score($recipe, $tokens, $bigrams),
            ];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        /** @var array{recipe: Recipe, score: int} $top */
        $top = $scored[0];
        $reason = $this->explain($top['recipe'], $tokens);

        $alternatives = [];
        for ($i = 1, $n = count($scored); $i < $n; $i++) {
            if ($scored[$i]['score'] < self::NOISE_FLOOR) {
                break;
            }
            $alternatives[] = ['recipe_id' => $scored[$i]['recipe']->id, 'score' => $scored[$i]['score']];
            if (count($alternatives) >= 3) {
                break;
            }
        }

        return new ClassificationResult(
            recipe: $top['recipe'],
            score: $top['score'],
            reason: $reason,
            suggested_module: $hintModule ?? $this->guessModule($description),
            alternatives: $alternatives,
        );
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $bigrams
     */
    private function score(Recipe $recipe, array $tokens, array $bigrams): int
    {
        $score = 0;
        foreach ($recipe->verbs as $verb) {
            if (in_array(strtolower($verb), $tokens, true)) {
                $score += self::WEIGHT_VERB;
            }
        }
        foreach ($recipe->keywords as $keyword) {
            if (in_array(strtolower($keyword), $tokens, true)) {
                $score += self::WEIGHT_KEYWORD;
            }
        }

        $labelBigrams = $this->bigrams($this->tokenize(strtolower($recipe->label)));
        foreach ($bigrams as $bg) {
            if (in_array($bg, $labelBigrams, true)) {
                $score += self::WEIGHT_LABEL_BIGRAM;
            }
        }
        return $score;
    }

    /**
     * @param list<string> $tokens
     */
    private function explain(Recipe $recipe, array $tokens): string
    {
        $matchedVerbs = array_values(array_intersect(array_map('strtolower', $recipe->verbs), $tokens));
        $matchedKeywords = array_values(array_intersect(array_map('strtolower', $recipe->keywords), $tokens));

        $parts = [];
        if ($matchedVerbs !== []) {
            $parts[] = 'verbs(' . implode(',', $matchedVerbs) . ')';
        }
        if ($matchedKeywords !== []) {
            $parts[] = 'keywords(' . implode(',', $matchedKeywords) . ')';
        }
        if ($parts === []) {
            return 'no strong signals — fell back to default ordering';
        }
        return 'matched ' . implode(' + ', $parts);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [];
        return array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function bigrams(array $tokens): array
    {
        $bigrams = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $bigrams[] = $tokens[$i] . ' ' . $tokens[$i + 1];
        }
        return $bigrams;
    }

    /**
     * Cheap heuristic: pull a StudlyCase token out of the description, treat it
     * as a likely module name. Returns null when nothing matches the shape.
     */
    private function guessModule(string $description): ?string
    {
        if (preg_match_all('/\b([A-Z][a-z][a-zA-Z0-9]*)\b/', $description, $matches) === false) {
            return null;
        }
        $candidates = $matches[1] ?? [];
        return $candidates[0] ?? null;
    }
}
