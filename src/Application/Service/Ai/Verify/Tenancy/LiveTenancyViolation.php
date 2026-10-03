<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Tenancy;

/**
 * One live-tenancy defect: either a live-bound resource with no declared
 * tenancy posture, or a watched scope no resource actually publishes
 * (a dead live wire — the grid looks live but never re-runs).
 */
final readonly class LiveTenancyViolation
{
    public const CODE_UNTENANTED = 'live_resource_untenanted';
    public const CODE_UNBACKED   = 'live_scope_unbacked';

    /** Why each check exists, and what taught us; same shape as the PHPStan rules' RATIONALE. */
    public const RATIONALES = [
        self::CODE_UNTENANTED => 'Why: a live re-run is tenant-filtered only when the resource carries #[TenantScoped], so a live-bound resource with no posture re-reads every tenant\'s rows into one tenant\'s stream. Learned 2026-06-17: the live-grid audit found every resource wired to a live scope without a tenancy posture, and nothing guarded it.',
        self::CODE_UNBACKED   => 'Why: a watched scope that nothing publishes is a dead wire: the grid looks live and never re-runs, which no single-request test can see. Policy since 2026-07-04 (live_tenancy guard); no incident on record.',
    ];

    /** @param list<class-string> $watchers */
    public function __construct(
        public string $code,
        public string $scopeKey,
        public array $watchers,
        public ?string $resourceClass = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'scope_key' => $this->scopeKey,
            'watchers' => $this->watchers,
            'resource' => $this->resourceClass,
            'message' => $this->message(),
            'rationale' => $this->rationale(),
        ];
    }

    public function rationale(): string
    {
        return self::RATIONALES[$this->code] ?? '';
    }

    public function message(): string
    {
        return match ($this->code) {
            self::CODE_UNTENANTED => sprintf(
                '%s is live-bound via scope "%s" (watched by %s) but declares neither #[TenantScoped] nor #[TenantExempt] — its re-run serves every tenant the same rows.',
                $this->resourceClass,
                $this->scopeKey,
                implode(', ', $this->watchers),
            ),
            self::CODE_UNBACKED => sprintf(
                'Scope "%s" (watched by %s) matches no resource key — this live wire never fires; check the scope key against the resource #[ResourceKey]/#[FromTable].',
                $this->scopeKey,
                implode(', ', $this->watchers),
            ),
            default => $this->code,
        };
    }
}
