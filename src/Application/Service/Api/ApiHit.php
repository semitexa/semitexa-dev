<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

/** One search result: a class, or one member of it. */
final readonly class ApiHit
{
    public function __construct(
        public int $score,
        public string $class,
        public string $classKind,
        public string $file,
        public bool $stable,
        /** Null when the class itself matched. */
        public ?string $member,
        public string $signature,
        public string $summary,
    ) {}

    /**
     * @return array{class: string, class_kind: string, file: string, stable: bool, member: ?string, signature: string, summary: string}
     */
    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'class_kind' => $this->classKind,
            'file' => $this->file,
            'stable' => $this->stable,
            'member' => $this->member,
            'signature' => $this->signature,
            'summary' => $this->summary,
        ];
    }
}
