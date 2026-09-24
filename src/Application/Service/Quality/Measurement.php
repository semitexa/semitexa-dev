<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * One reading of a metric: the total, and where it comes from.
 *
 * The breakdown is what makes the ratchet honest. With a total alone, one
 * package gaining a problem while another loses one reads as "unchanged" —
 * the regression rides in on somebody else's fix.
 */
final readonly class Measurement
{
    /** @var array<string, int> */
    public array $breakdown;

    /**
     * @param array<string, int> $breakdown key => count; zero entries are dropped
     */
    public function __construct(array $breakdown)
    {
        $kept = array_filter($breakdown, static fn (int $n): bool => $n !== 0);
        ksort($kept);
        $this->breakdown = $kept;
    }

    public function total(): int
    {
        return array_sum($this->breakdown);
    }
}
