<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Impact;

/**
 * Blast-radius reading for a single changed file.
 *
 * `band` is the shared low|medium|high risk vocabulary (aligned with
 * ai:plan's RiskScorer) plus two honesty sentinels:
 *   - `unresolved` — the file has no node in the graph (e.g. a config or
 *     fixture); impact is not applicable, not "safe".
 *   - `unknown`    — the graph is stale/absent, so no band can be trusted.
 */
final readonly class FileImpact
{
    public function __construct(
        public string $path,
        public bool $resolved,
        public int $dependents,
        public int $modulesAffected,
        public string $band,
    ) {
    }

    public static function unresolved(string $path): self
    {
        return new self($path, false, 0, 0, 'unresolved');
    }

    public static function unknown(string $path): self
    {
        return new self($path, false, 0, 0, 'unknown');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path'             => $this->path,
            'band'             => $this->band,
            'dependents'       => $this->dependents,
            'modules_affected' => $this->modulesAffected,
            'resolved'         => $this->resolved,
        ];
    }
}
