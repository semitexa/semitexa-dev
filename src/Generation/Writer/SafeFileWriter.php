<?php

declare(strict_types=1);

namespace Semitexa\Dev\Generation\Writer;

use Semitexa\Dev\Generation\Contract\FileWriterInterface;
use Semitexa\Dev\Generation\Data\GenerationResult;
use Semitexa\Dev\Generation\Data\PlannedFile;

final class SafeFileWriter implements FileWriterInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $commandName = 'unknown',
    ) {}

    public function write(array $files, bool $force = false): GenerationResult
    {
        $created = [];
        $skipped = [];
        $conflicts = [];

        foreach ($files as $file) {
            $fullPath = $this->basePath . '/' . ltrim($file->path, '/');

            if (file_exists($fullPath)) {
                if ($force) {
                    $this->writeFile($fullPath, $file->content);
                    $created[] = $file->path;
                } else {
                    $conflicts[] = $file->path;
                }
                continue;
            }

            $this->writeFile($fullPath, $file->content);
            $created[] = $file->path;
        }

        $status = match (true) {
            count($conflicts) > 0 && count($created) === 0 => 'conflict',
            count($conflicts) > 0 => 'partial',
            default => 'success',
        };

        $nextSteps = [];
        if ($conflicts) {
            $nextSteps[] = 'Use --force to overwrite conflicting files';
        }

        return new GenerationResult(
            command: $this->commandName,
            status: $status,
            created: $created,
            skipped: $skipped,
            conflicts: $conflicts,
            next_steps: $nextSteps,
        );
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }
}
