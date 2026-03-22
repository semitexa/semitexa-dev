<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Data;

final readonly class CapabilityManifest
{
    /**
     * @param string $artifact
     * @param string $generated_at
     * @param list<CommandCapability> $commands
     */
    public function __construct(
        public string $artifact,
        public string $generated_at,
        public array $commands,
    ) {}

    public function toArray(): array
    {
        return [
            'artifact' => $this->artifact,
            'generated_at' => $this->generated_at,
            'commands' => array_map(fn(CommandCapability $c) => $c->toArray(), $this->commands),
        ];
    }
}
