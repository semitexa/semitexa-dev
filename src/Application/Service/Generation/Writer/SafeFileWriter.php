<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Generation\Writer;

use Semitexa\Dev\Application\Service\Generation\Contract\FileWriterInterface;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;

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

        $verify = $this->verifyCreated($created);

        return new GenerationResult(
            command: $this->commandName,
            status: $status,
            created: $created,
            skipped: $skipped,
            conflicts: $conflicts,
            next_steps: $nextSteps,
            verify: $verify,
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

    /**
     * Run `php -l` on each newly-created .php file. Returns null when no PHP
     * files were created (keeps the envelope small for non-PHP outputs).
     *
     * @param list<string> $created
     * @return array{status: string, checked: int, errors: list<array{file: string, message: string}>}|null
     */
    private function verifyCreated(array $created): ?array
    {
        $phpFiles = array_values(array_filter(
            $created,
            static fn(string $rel): bool => str_ends_with($rel, '.php'),
        ));

        if ($phpFiles === []) {
            return null;
        }

        $phpBin = $this->resolvePhpBinary();
        if ($phpBin === null) {
            return ['status' => 'skipped', 'checked' => 0, 'errors' => []];
        }

        $errors = [];
        foreach ($phpFiles as $rel) {
            $full = $this->basePath . '/' . ltrim($rel, '/');
            if (!is_file($full)) {
                $errors[] = ['file' => $rel, 'message' => 'created file is missing during verification'];
                continue;
            }

            $descriptors = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = @proc_open([$phpBin, '-l', '-n', $full], $descriptors, $pipes);
            if (!is_resource($process)) {
                $errors[] = ['file' => $rel, 'message' => 'could not invoke php -l'];
                continue;
            }

            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode !== 0) {
                $message = trim($stderr !== '' ? $stderr : $stdout);
                $errors[] = [
                    'file'    => $rel,
                    'message' => $message !== '' ? $message : "php -l exited with code {$exitCode}",
                ];
            }
        }

        return [
            'status'  => $errors === [] ? 'pass' : 'fail',
            'checked' => count($phpFiles),
            'errors'  => $errors,
        ];
    }

    private function resolvePhpBinary(): ?string
    {
        if (PHP_BINARY !== '' && (is_file(PHP_BINARY) || is_executable(PHP_BINARY))) {
            return PHP_BINARY;
        }

        $resolved = trim((string) @shell_exec('command -v php 2>/dev/null'));

        return $resolved !== '' ? $resolved : null;
    }
}
