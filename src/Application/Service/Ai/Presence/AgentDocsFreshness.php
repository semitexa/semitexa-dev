<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

use Composer\InstalledVersions;

/**
 * Whether the project's AGENTS.md is the manual the installed framework ships.
 *
 * Until 2026-10-17 nothing updated it: `init --only-docs` writes AGENTS.md only
 * when it is missing, so every project kept the manual it was installed with.
 * The portal's was five months old and had no "Say you are here" directive, so
 * its agents never joined and nobody saw them. `bin/semitexa update` now
 * carries the agent docs; this says when a project has not taken them, by
 * hashing AGENTS.md against semitexa/update's scaffold manifest.
 */
final class AgentDocsFreshness
{
    public const CURRENT = 'current';
    /** A released version, not the newest: `bin/semitexa update` replaces it. */
    public const BEHIND = 'behind';
    /** No released version: edited here, so update leaves AGENTS.md.new to merge. */
    public const EDITED = 'edited';
    public const MISSING = 'missing';
    /** No manifest to compare with (semitexa/update not installed): nothing to say. */
    public const UNKNOWN = 'unknown';

    private const MANIFEST = 'resources/scaffold-manifest.json';

    public function __construct(private readonly string $projectRoot, private readonly ?string $manifestPath = null)
    {
    }

    public function agentsMd(): string
    {
        $file = $this->projectRoot . '/AGENTS.md';
        if (!is_file($file)) {
            return self::MISSING;
        }
        $entry = $this->entry('AGENTS.md');
        if ($entry === null) {
            return self::UNKNOWN;
        }
        $hash = (string) hash_file('sha256', $file);

        return match (true) {
            $hash === ($entry['current_sha256'] ?? null) => self::CURRENT,
            in_array($hash, (array) ($entry['previous_sha256'] ?? []), true) => self::BEHIND,
            default => self::EDITED,
        };
    }

    /** @return array<string, mixed>|null */
    private function entry(string $path): ?array
    {
        $manifest = $this->manifestPath ?? $this->findManifest();
        if ($manifest === null || !is_file($manifest)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($manifest), true);
        foreach (is_array($data) ? (array) ($data['files'] ?? []) : [] as $entry) {
            if (is_array($entry) && ($entry['path'] ?? null) === $path) {
                return $entry;
            }
        }

        return null;
    }

    private function findManifest(): ?string
    {
        $candidates = [];
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('semitexa/update')) {
            $installed = InstalledVersions::getInstallPath('semitexa/update');
            if ($installed !== null) {
                $candidates[] = $installed . '/' . self::MANIFEST;
            }
        }
        $candidates[] = $this->projectRoot . '/vendor/semitexa/update/' . self::MANIFEST;
        $candidates[] = $this->projectRoot . '/packages/semitexa-update/' . self::MANIFEST;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
