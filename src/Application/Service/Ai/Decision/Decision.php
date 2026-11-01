<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Decision;

/**
 * One decision of the project's owner (ep-project-charter): their words, what
 * it means for the work, what it applies to, and whether it stands or allows
 * a single act. An agent reads these before asking the owner, and records the
 * answer as one after; a one-time approval (a release) is consumed by the act
 * it allowed, so the next one is asked for again.
 */
final readonly class Decision
{
    public const SCHEMA_VERSION = 'semitexa.ai-decision/v1';

    public function __construct(
        public string $id,
        public string $words,
        public string $means,
        public string $appliesTo,
        public DecisionScope $scope,
        public string $decidedAt,
        public string $by = 'owner',
        public ?string $expiresAt = null,
        public bool $revoked = false,
        public ?string $consumedAt = null,
        public ?string $consumedBy = null,
    ) {}

    public function status(\DateTimeImmutable $now): DecisionStatus
    {
        return match (true) {
            $this->revoked => DecisionStatus::REVOKED,
            $this->consumedAt !== null => DecisionStatus::CONSUMED,
            $this->expiresAt !== null && new \DateTimeImmutable($this->expiresAt) <= $now => DecisionStatus::EXPIRED,
            default => DecisionStatus::ACTIVE,
        };
    }

    /** A time with an explicit offset: one without would be read in whatever zone PHP runs in. */
    public static function isInstant(string $at): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $at);

        // Round-tripped: 2026-02-30T… parses, as March 2.
        return $parsed !== false && $parsed->format(\DateTimeInterface::ATOM) === $at;
    }

    public function consumed(string $at, string $by): self
    {
        return new self($this->id, $this->words, $this->means, $this->appliesTo, $this->scope, $this->decidedAt, $this->by, $this->expiresAt, $this->revoked, $at, $by);
    }

    public function revokedNow(): self
    {
        return new self($this->id, $this->words, $this->means, $this->appliesTo, $this->scope, $this->decidedAt, $this->by, $this->expiresAt, true, $this->consumedAt, $this->consumedBy);
    }

    /** @return array<string, mixed> */
    public function toFileArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'id' => $this->id,
            'words' => $this->words,
            'means' => $this->means,
            'applies_to' => $this->appliesTo,
            'scope' => $this->scope->value,
            'by' => $this->by,
            'decided_at' => $this->decidedAt,
            'expires_at' => $this->expiresAt,
            'revoked' => $this->revoked,
            'consumed_at' => $this->consumedAt,
            'consumed_by' => $this->consumedBy,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(\DateTimeImmutable $now): array
    {
        return $this->toFileArray() + ['status' => $this->status($now)->value];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('not a ' . self::SCHEMA_VERSION . ' decision');
        }
        $text = static function (string $key, bool $required) use ($data): ?string {
            $value = $data[$key] ?? null;
            if ($value === null && !$required) {
                return null;
            }
            if (!is_string($value) || ($required && trim($value) === '')) {
                throw new \RuntimeException("decision {$key} must be " . ($required ? 'non-empty text' : 'text or null'));
            }

            return $value;
        };
        $scope = DecisionScope::tryFrom(is_string($data['scope'] ?? null) ? $data['scope'] : '');
        if ($scope === null) {
            throw new \RuntimeException('decision scope must be standing or once');
        }
        if (!is_bool($data['revoked'] ?? false)) {
            throw new \RuntimeException('decision revoked must be true or false');
        }
        foreach (['decided_at' => true, 'expires_at' => false, 'consumed_at' => false] as $key => $required) {
            $at = $text($key, $required);
            if ($at !== null && !self::isInstant($at)) {
                throw new \RuntimeException("decision {$key} must be an ISO-8601 time with its offset, got \"{$at}\"");
            }
        }

        $consumedAt = $text('consumed_at', false);
        $consumedBy = $text('consumed_by', false);
        if (($consumedAt === null) !== ($consumedBy === null)) {
            throw new \RuntimeException('decision consumed_at and consumed_by are set together or not at all');
        }
        if ($consumedAt !== null && $scope !== DecisionScope::ONCE) {
            throw new \RuntimeException('a standing decision is revoked, never consumed');
        }

        return new self(
            id: (string) $text('id', true),
            words: (string) $text('words', true),
            means: (string) $text('means', true),
            appliesTo: (string) $text('applies_to', true),
            scope: $scope,
            decidedAt: (string) $text('decided_at', true),
            by: (string) $text('by', true),
            expiresAt: $text('expires_at', false),
            revoked: (bool) ($data['revoked'] ?? false),
            consumedAt: $text('consumed_at', false),
            consumedBy: $text('consumed_by', false),
        );
    }
}
