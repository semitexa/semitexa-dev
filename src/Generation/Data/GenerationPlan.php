<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Data;

final readonly class GenerationPlan
{
    /**
     * @param string $command
     * @param list<PlannedFile> $files
     * @param bool $dryRun
     */
    public function __construct(
        public string $command,
        public array $files,
        public bool $dryRun = false,
    ) {}

    public function toArray(): array
    {
        return [
            'command' => $this->command,
            'files' => array_map(fn(PlannedFile $f) => $f->toArray(), $this->files),
            'dry_run' => $this->dryRun,
        ];
    }
}
