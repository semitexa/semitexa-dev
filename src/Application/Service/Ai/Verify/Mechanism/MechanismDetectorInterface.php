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
    /**
     * File extensions this detector reads, without the dot.
     *
     * A list rather than one string because the planner and the detectors have
     * to agree on what a "client script" is. `ChangedFileClassifier` counts both
     * `.js` and `.mjs`, so a single extension meant a changed `.mjs` scheduled
     * this lint and the lint then scanned no files at all — a pass that had
     * examined nothing, which is worse than not running.
     *
     * @return non-empty-list<string>
     */
    public function extensions(): array;

    /**
     * @param list<string> $lines
     * @return list<MechanismFinding>
     */
    public function detect(string $file, array $lines): array;
}
