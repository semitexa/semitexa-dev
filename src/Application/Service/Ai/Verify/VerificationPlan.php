<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Frozen output of {@see VerificationPlanner}. The command executes `targets`
 * in order; `expansions` records any auto-bumps so the NDJSON report can
 * explain why a narrow request turned into a broad one.
 */
final readonly class VerificationPlan
{
    public const SCOPE_MINIMAL  = 'minimal';
    public const SCOPE_STANDARD = 'standard';
    public const SCOPE_BROAD    = 'broad';

    /**
     * @param list<ChangedFile>        $changedFiles
     * @param list<VerificationTarget> $targets
     * @param list<string>             $expansions reasons scope was auto-bumped
     */
    public function __construct(
        public string $scope,
        public string $effectiveScope,
        public array $changedFiles,
        public array $targets,
        public array $expansions = [],
    ) {}

    /** Whether it opens pages of the running server: the restart note depends on it (tk-page-check-stale-route). */
    public function opensPages(): bool
    {
        foreach ($this->targets as $target) {
            if ($target->type === VerificationTarget::TYPE_PAGE) {
                return true;
            }
        }

        return false;
    }
}
