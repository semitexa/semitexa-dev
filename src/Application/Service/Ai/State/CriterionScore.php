<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/** The points one criterion holds, and the evidence that sets them. */
final readonly class CriterionScore
{
    public function __construct(
        public string $criterionId,
        public int $points,
        public string $evidence,
    ) {}
}
