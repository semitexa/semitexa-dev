<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * One detector for one way a framework mechanism gets rebuilt by hand.
 *
 * Kept as separate small implementations rather than one growing matcher, so
 * each carries its own silence cases. The failure mode for this whole channel
 * is a detector that fires on plausible code; isolating them means a new one
 * cannot quietly widen an existing one, and each can be measured against the
 * repository on its own before it ships.
 */
interface MechanismDetectorInterface
{
    /** File extension this detector reads, without the dot. */
    public function extension(): string;

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array;
}
