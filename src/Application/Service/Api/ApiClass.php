<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

/** What a caller needs from a class, without opening its file. */
final readonly class ApiClass
{
    /** Past this a class is better read than listed; the omitted count says so. */
    public const int MAX_MEMBERS = 60;

    public const int MAX_IMPLEMENTERS = 20;

    /**
     * @param list<string>       $traits
     * @param list<ApiParameter> $parameters
     * @param list<ApiMember>    $members
     * @param list<string>       $implementers
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $file,
        public string $summary,
        /** Marked `@api`: covered by the stability contract. */
        public bool $stable,
        public ?string $extends,
        public array $traits,
        public array $parameters,
        public array $members,
        public array $implementers = [],
    ) {}

    /**
     * @param list<ApiParameter> $parameters
     * @param list<string>       $implementers
     */
    public function with(array $parameters, array $implementers): self
    {
        return new self(
            $this->name,
            $this->kind,
            $this->file,
            $this->summary,
            $this->stable,
            $this->extends,
            $this->traits,
            $parameters,
            $this->members,
            $implementers,
        );
    }

    public function shortName(): string
    {
        $separator = strrpos($this->name, '\\');

        return $separator === false ? $this->name : substr($this->name, $separator + 1);
    }

    /**
     * @return list<ApiMember>
     */
    public function shownMembers(): array
    {
        return array_slice($this->members, 0, self::MAX_MEMBERS);
    }

    public function omittedMembers(): int
    {
        return max(0, count($this->members) - self::MAX_MEMBERS);
    }

    /**
     * @return list<string>
     */
    public function shownImplementers(): array
    {
        return array_slice($this->implementers, 0, self::MAX_IMPLEMENTERS);
    }

    public function omittedImplementers(): int
    {
        return max(0, count($this->implementers) - self::MAX_IMPLEMENTERS);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'file' => $this->file,
            'summary' => $this->summary,
            'stable' => $this->stable,
            'extends' => $this->extends,
            'traits' => $this->traits,
            'parameters' => array_map(static fn (ApiParameter $parameter): array => $parameter->toArray(), $this->parameters),
            'members' => array_map(static fn (ApiMember $member): array => $member->toArray(), $this->shownMembers()),
            'omitted_members' => $this->omittedMembers(),
            'implementers' => $this->shownImplementers(),
            'omitted_implementers' => $this->omittedImplementers(),
        ];
    }
}
