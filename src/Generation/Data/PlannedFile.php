<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Data;

final readonly class PlannedFile
{
    public function __construct(
        public string $path,
        public string $content,
        public FileType $type,
    ) {}

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'type' => $this->type->value,
        ];
    }
}
