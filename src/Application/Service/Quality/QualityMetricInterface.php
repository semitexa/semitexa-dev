<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * A number about the codebase where lower is better.
 *
 * Declared with {@see \Semitexa\Dev\Attribute\AsQualityMetric}.
 */
interface QualityMetricInterface
{
    /**
     * Measure the tree under $projectRoot. Must not throw for an absent
     * directory: a project without packages/ measures zero, it does not fail.
     */
    public function measure(string $projectRoot): Measurement;
}
