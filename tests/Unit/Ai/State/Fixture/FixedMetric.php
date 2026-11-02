<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State\Fixture;

use Semitexa\Dev\Application\Service\Quality\Measurement;
use Semitexa\Dev\Application\Service\Quality\QualityMetricInterface;

/** A metric that reads what it is given, or fails as one that cannot read the tree does. */
final class FixedMetric implements QualityMetricInterface
{
    /** @param array<string, int> $breakdown */
    public function __construct(private readonly array $breakdown, private readonly ?string $failure = null) {}

    public function measure(string $projectRoot): Measurement
    {
        if ($this->failure !== null) {
            throw new \RuntimeException($this->failure);
        }

        return new Measurement($this->breakdown);
    }
}
