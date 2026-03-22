<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Data;

final readonly class GenerationResult
{
    /**
     * @param string $command
     * @param string $status
     * @param list<string> $created
     * @param list<string> $skipped
     * @param list<string> $conflicts
     * @param list<string> $next_steps
     */
    public function __construct(
        public string $command,
        public string $status,
        public array $created = [],
        public array $skipped = [],
        public array $conflicts = [],
        public array $next_steps = [],
    ) {}

    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'status' => $this->status,
            'created' => $this->created,
            'skipped' => $this->skipped,
            'conflicts' => $this->conflicts,
            'next_steps' => $this->next_steps,
        ];
    }
}
