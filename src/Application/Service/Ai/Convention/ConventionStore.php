<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Convention;

/**
 * Persists module conventions as JSON in `var/cache/ai/conventions.json`.
 *
 * Read path:
 *   - {@see findForModule()} returns null if the cache file is missing or the
 *     module is unknown — callers fall back to vanilla generation.
 *
 * Write path:
 *   - {@see writeAll()} replaces the cache atomically (temp file + rename).
 *
 * The store deliberately exposes no auto-mine: re-mining is an explicit
 * operator action via the `dev:graph:mine-conventions` command. Scaffolders
 * should never silently spend time scanning the codebase mid-write.
 */
final class ConventionStore
{
    private const SCHEMA = 'semitexa.ai-conventions/v1';
    public const RELATIVE_PATH = 'var/cache/ai/conventions.json';

    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function path(): string
    {
        return $this->projectRoot . '/' . self::RELATIVE_PATH;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function findForModule(string $module): ?ModuleConventions
    {
        $payload = $this->readPayload();
        if ($payload === null) {
            return null;
        }
        $modules = $this->modules($payload);
        if (!isset($modules[$module])) {
            return null;
        }
        return ModuleConventions::fromArray(['module' => $module] + $modules[$module]);
    }

    /**
     * @return array<string, ModuleConventions>
     */
    public function readAll(): array
    {
        $payload = $this->readPayload();
        if ($payload === null) {
            return [];
        }
        $out = [];
        foreach ($this->modules($payload) as $name => $row) {
            $out[$name] = ModuleConventions::fromArray(['module' => $name] + $row);
        }
        return $out;
    }

    /**
     * @param array<string, ModuleConventions> $byModule
     */
    public function writeAll(array $byModule): void
    {
        $modules = [];
        foreach ($byModule as $name => $conv) {
            $modules[$name] = $conv->toArray();
        }
        $payload = [
            'schema'        => self::SCHEMA,
            'generated_at'  => date('c'),
            'module_count'  => count($modules),
            'modules'       => $modules,
        ];

        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create cache directory: {$dir}");
        }
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        rename($tmp, $path);
    }

    /**
     * The cache's rows by module name: each one an array, or the file is refused.
     *
     * @param array<array-key, mixed> $payload
     * @return array<string, array<array-key, mixed>>
     *
     * @throws \InvalidArgumentException
     */
    private function modules(array $payload): array
    {
        $modules = $payload['modules'] ?? [];
        if (!is_array($modules)) {
            throw new \InvalidArgumentException(sprintf('The conventions cache (%s) is refused: modules is %s, and it must be a map of module name to conventions. Rebuild it with `bin/semitexa dev:graph:mine-conventions`.', self::RELATIVE_PATH, get_debug_type($modules)));
        }
        $out = [];
        foreach ($modules as $name => $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(sprintf('The conventions cache (%s) is refused: modules.%s is %s, and it must be a map of conventions. Rebuild it with `bin/semitexa dev:graph:mine-conventions`.', self::RELATIVE_PATH, $name, get_debug_type($row)));
            }
            $out[(string) $name] = $row;
        }

        return $out;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function readPayload(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
