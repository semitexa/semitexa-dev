<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * How a computed criterion's points follow from its reading: Semitexa keeps
 * the score, not whoever ran the last tick. Before formulas a person or agent
 * typed the points with `ai:state score`, and on 2026-11-02 the board moved
 * down the moment a defect was found but not up while the phpstan baseline
 * fell from 502 to 317 entries: the counting was only as even as the counter.
 * A formula is declared beside the rubric, in words (shown with the score)
 * and in code, and clamped to the criterion's weight.
 */
final readonly class CriterionFormula
{
    /**
     * @param \Closure(array<string, int>): ?int $points the reading's values => points, null when it says nothing usable
     */
    public function __construct(
        public string $text,
        private \Closure $points,
    ) {}

    /** @param array<string, int> $values */
    public function points(array $values, int $weight): ?int
    {
        $points = ($this->points)($values);

        return $points === null ? null : max(0, min($weight, $points));
    }
}
