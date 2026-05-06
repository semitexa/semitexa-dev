<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;

/**
 * Resolves a list of changed files to the set of modules / packages whose
 * structure must be validated.
 *
 * A module root is one of:
 *
 *   - `src/modules/{Name}/`                     application module
 *   - `packages/semitexa-{name}/`  (with `composer.json` present)  package
 *
 * Files outside either shape are ignored — the structure check is opt-in by
 * location. The resolver returns module roots in a stable lexicographic
 * order so NDJSON output is deterministic.
 *
 * Detection is path-only: a single composer.json existence check per
 * package candidate is the only filesystem read. The resolver never opens
 * source files or evaluates namespaces.
 */
final class ModuleStructureTargetResolver
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    /**
     * @param list<ChangedFile> $files
     * @return list<DetectedModule>
     */
    public function resolve(array $files): array
    {
        $modules = [];
        foreach ($files as $file) {
            $module = $this->resolveOne($file->path);
            if ($module === null) {
                continue;
            }
            $modules[$module->relativePath] = $module;
        }
        ksort($modules);
        return array_values($modules);
    }

    public function resolveOne(string $relPath): ?DetectedModule
    {
        $normalised = ltrim(str_replace('\\', '/', $relPath), '/');
        $parts = explode('/', $normalised);

        if (count($parts) >= 4 && $parts[0] === 'src' && $parts[1] === 'modules') {
            $name = $parts[2];
            if ($name === '' || $name === '.' || $name === '..') {
                return null;
            }
            return new DetectedModule(
                name: $name,
                relativePath: 'src/modules/' . $name,
                kind: DetectedModule::KIND_APPLICATION,
            );
        }

        if (count($parts) >= 2 && $parts[0] === 'packages' && str_starts_with($parts[1], 'semitexa-')) {
            $name = $parts[1];
            if ($name === '' || $name === '.' || $name === '..') {
                return null;
            }
            $relativePath = 'packages/' . $name;
            if (!is_file($this->projectRoot . '/' . $relativePath . '/composer.json')) {
                return null;
            }
            return new DetectedModule(
                name: substr($name, strlen('semitexa-')),
                relativePath: $relativePath,
                kind: DetectedModule::KIND_PACKAGE,
            );
        }

        return null;
    }
}
