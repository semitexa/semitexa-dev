<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Plan;

final readonly class RiskAssessment
{
    /**
     * @param list<string> $reasons
     * @param list<string> $required_steps
     */
    public function __construct(
        public string $level,
        public int $score,
        public array $reasons,
        public array $required_steps,
    ) {}
}
