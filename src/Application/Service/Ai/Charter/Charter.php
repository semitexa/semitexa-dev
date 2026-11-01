<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

/**
 * The project's direction, as its owner gave it (ep-project-charter): what it
 * is for, for whom, the problem it solves, how success shows, what must hold,
 * what it deliberately does not do, and what an agent must ask the owner about
 * rather than decide.
 *
 * Null is "not asked yet"; an empty list is an answer ("none"). That is what
 * lets {@see CharterInterview} know the next question without a second record.
 */
final readonly class Charter
{
    public const SCHEMA_VERSION = 'semitexa.ai-charter/v1';

    /**
     * What an agent asks the owner about by default; everything technical it
     * decides itself.
     */
    public const DEFAULT_OWNER_DECIDES = [
        'the project goal, and what it will not do',
        'new dependencies and platform requirements',
        'spending money',
        'anything public: releases, posts, emails, announcements',
        'anything that cannot be undone: deleting data, history or accounts',
    ];

    /**
     * @param list<SuccessSign> $signs
     * @param list<string>|null $constraints
     * @param list<string>|null $nonGoals
     * @param list<string>|null $ownerDecides
     */
    public function __construct(
        public ?string $goal = null,
        public ?string $audience = null,
        public ?string $problem = null,
        public array $signs = [],
        public ?array $constraints = null,
        public ?array $nonGoals = null,
        public ?array $ownerDecides = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @param list<SuccessSign>|null $signs
     * @param list<string>|null $constraints
     * @param list<string>|null $nonGoals
     * @param list<string>|null $ownerDecides
     */
    public function with(
        ?string $goal = null,
        ?string $audience = null,
        ?string $problem = null,
        ?array $signs = null,
        ?array $constraints = null,
        ?array $nonGoals = null,
        ?array $ownerDecides = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            goal: $goal ?? $this->goal,
            audience: $audience ?? $this->audience,
            problem: $problem ?? $this->problem,
            signs: $signs ?? $this->signs,
            constraints: $constraints ?? $this->constraints,
            nonGoals: $nonGoals ?? $this->nonGoals,
            ownerDecides: $ownerDecides ?? $this->ownerDecides,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'goal' => $this->goal,
            'audience' => $this->audience,
            'problem' => $this->problem,
            'signs' => array_map(static fn (SuccessSign $s): array => $s->toArray(), $this->signs),
            'constraints' => $this->constraints,
            'non_goals' => $this->nonGoals,
            'owner_decides' => $this->ownerDecides,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * A file that does not read as a charter is refused, never taken as an
     * empty one: an agent would then re-ask the owner everything.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('not a ' . self::SCHEMA_VERSION . ' charter');
        }
        $signs = $data['signs'] ?? [];
        if (!is_array($signs) || !array_is_list($signs)) {
            throw new \RuntimeException('charter signs must be a list');
        }

        return new self(
            goal: self::text($data, 'goal'),
            audience: self::text($data, 'audience'),
            problem: self::text($data, 'problem'),
            signs: array_map(static function (mixed $sign): SuccessSign {
                if (!is_array($sign)) {
                    throw new \RuntimeException('a charter sign must be an object');
                }
                /** @var array<string, mixed> $sign */
                return SuccessSign::fromArray($sign);
            }, $signs),
            constraints: self::texts($data, 'constraints'),
            nonGoals: self::texts($data, 'non_goals'),
            ownerDecides: self::texts($data, 'owner_decides'),
            updatedAt: self::text($data, 'updated_at'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function text(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value !== null && !is_string($value)) {
            throw new \RuntimeException("charter {$key} must be text");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<string>|null
     */
    private static function texts(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw new \RuntimeException("charter {$key} must be a list of texts");
        }

        return $value;
    }
}
