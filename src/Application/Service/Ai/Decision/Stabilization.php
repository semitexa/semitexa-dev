<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Decision;

/**
 * The stabilization mode: on while the owner has an active decision whose id
 * starts with `stabilization-` (ai:decision stabilization-2026-11 is the
 * first). It ends by itself when that decision expires or is revoked.
 *
 * While it is on, a finding counts only as a blocker with evidence
 * ({@see \Semitexa\Dev\Application\Service\Ai\Work\TaskFiling}); everything
 * else is filed as an idea. The commands read the mode here and pass it on,
 * so the rules of filing stay free of the store.
 */
final readonly class Stabilization
{
    public const ID_PREFIX = 'stabilization-';

    public function __construct(
        public string $id,
        public ?string $expiresAt,
    ) {}

    /**
     * The mode, or null when it is off. With several such decisions active
     * the one that lasts longest speaks; one with no expiry outlasts them all.
     *
     * @throws \RuntimeException when the decisions cannot be read: off is never guessed
     */
    public static function of(DecisionStore $store, \DateTimeImmutable $now): ?self
    {
        $mode = null;
        foreach ($store->list() as $decision) {
            if (!str_starts_with($decision->id, self::ID_PREFIX) || $decision->status($now) !== DecisionStatus::ACTIVE) {
                continue;
            }
            if ($mode === null || ($mode->expiresAt !== null && ($decision->expiresAt === null || new \DateTimeImmutable($decision->expiresAt) > new \DateTimeImmutable($mode->expiresAt)))) {
                $mode = new self($decision->id, $decision->expiresAt);
            }
        }

        return $mode;
    }

    /** @throws \RuntimeException when the decisions cannot be read */
    public static function isOn(DecisionStore $store): bool
    {
        return self::of($store, new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) !== null;
    }

    /** "Stabilization mode until 2026-11-27T00:00:00+00:00 (ai:decision stabilization-2026-11)" */
    public function label(): string
    {
        return 'Stabilization mode ' . ($this->expiresAt !== null ? "until {$this->expiresAt}" : 'until revoked') . " (ai:decision {$this->id})";
    }

    /** @return array{id: string, expires_at: ?string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'expires_at' => $this->expiresAt];
    }
}
