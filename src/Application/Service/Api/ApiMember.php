<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

/** A method, property, constant or enum case a caller can reach. */
final readonly class ApiMember
{
    public function __construct(
        public string $kind,
        public string $name,
        public string $signature,
        public string $summary,
        /** The declaring class when it is not the one being described. */
        public ?string $from = null,
    ) {}

    /**
     * @return array{kind: string, name: string, signature: string, summary: string, from: ?string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'name' => $this->name,
            'signature' => $this->signature,
            'summary' => $this->summary,
            'from' => $this->from,
        ];
    }
}
