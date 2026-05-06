<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Classifier;

use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;

final readonly class ClassificationResult
{
    /**
     * @param list<array{recipe_id: string, score: int}> $alternatives Other recipes that scored above the noise floor.
     */
    public function __construct(
        public Recipe $recipe,
        public int $score,
        public string $reason,
        public ?string $suggested_module,
        public array $alternatives = [],
    ) {}
}
