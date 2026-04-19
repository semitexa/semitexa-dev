<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

/**
 * Output of a single-item hygiene check. {@see $issues} is human-readable
 * (short slugs like `placeholder-title`, `no-goal`, `orphan`) — they feed
 * directly into `ai:backlog clean` output so the operator can see *why* an
 * item was flagged.
 *
 * {@see $suggestedStatus} is the hygiene service's recommendation (e.g.
 * promote stale done → archived, junk → discarded). Nothing mutates until
 * `ai:backlog clean --apply` runs.
 */
final readonly class HygieneAssessment
{
    /**
     * @param list<string> $issues
     */
    public function __construct(
        public BacklogQuality $quality,
        public array $issues,
        public ?string $suggestedStatus,
        public ?string $suggestedAction,
    ) {}

    public function hasIssue(string $slug): bool
    {
        return in_array($slug, $this->issues, true);
    }
}
