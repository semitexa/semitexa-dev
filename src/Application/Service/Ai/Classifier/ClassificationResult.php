<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Classifier;

use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;

final readonly class ClassificationResult
{
    /** Score ≥ reliable bar with a clear lead — the recipe can be trusted to drive execution. */
    public const CONFIDENCE_HIGH = 'high';
    /** Above the noise floor but weak or ambiguous — clarify before executing (AGENTS.md §2[1]/§3). */
    public const CONFIDENCE_LOW = 'low';
    /** Below the noise floor — honest no-match; the recipe field is a placeholder. */
    public const CONFIDENCE_NONE = 'none';

    /**
     * @param list<array{recipe_id: string, score: int}> $alternatives Other recipes that scored above the noise floor.
     * @param 'high'|'low'|'none' $confidence How much the score can be trusted to drive the next step.
     */
    public function __construct(
        public Recipe $recipe,
        public int $score,
        public string $reason,
        public ?string $suggested_module,
        public array $alternatives = [],
        public string $confidence = self::CONFIDENCE_LOW,
    ) {}
}
