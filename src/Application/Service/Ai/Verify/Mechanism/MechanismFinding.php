<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * One place where something the framework already does was built by hand.
 *
 * Carries only the observation and the capability id — never the advice text.
 * The wording is looked up from the `#[Capability]` catalog at report time, so
 * a finding cannot describe a mechanism differently from the mechanism's own
 * declaration. Copying the prose here is how the two would drift, and a rule
 * that recommends something in outdated terms is worse than one that stays
 * quiet.
 */
final readonly class MechanismFinding
{
    public function __construct(
        public string $file,
        public int $line,
        public string $capabilityId,
        public string $evidence,
    ) {
    }
}
