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
        ];
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
