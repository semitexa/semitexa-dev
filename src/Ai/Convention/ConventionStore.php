<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Convention;

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
 * operator action via the `ai:mine-conventions` command. Scaffolders should
 * never silently spend time scanning the codebase mid-write.
 */
final class ConventionStore
{
    private const SCHEMA = 'semitexa.ai-conventions/v1';
    private const RELATIVE_PATH = 'var/cache/ai/conventions.json';

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
        $modules = $payload['modules'] ?? [];
        if (!isset($modules[$module])) {
            return null;
        }
        return ModuleConventions::fromArray($modules[$module] + ['module' => $module]);
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
        foreach ($payload['modules'] ?? [] as $name => $row) {
            $out[$name] = ModuleConventions::fromArray($row + ['module' => $name]);
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
     * @return array<string, mixed>|null
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
