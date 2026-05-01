<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Similarity;

/**
 * A queryable snapshot of existing payload / handler / event-listener /
 * resource classes under `src/modules` and `packages/`.
 *
 * Built once per command invocation (no background cache). The underlying
 * scanner is token-based and never autoloads — safe to run inside a
 * scaffolder without triggering side effects.
 *
 * The index deliberately stores only what the duplicate detector needs:
 * module, class name, file path, and a few attribute-derived extras
 * (route path/method for payloads, event FQCN for listeners). Anything
 * richer belongs in the graph substrate, not here.
 */
final class SimilarityIndex
{
    /** @var array<string, list<IndexedArtifact>> */
    private array $byKind = [];

    /**
     * @param list<IndexedArtifact> $artifacts
     */
    public function __construct(array $artifacts)
    {
        foreach ($artifacts as $artifact) {
            $this->byKind[$artifact->kind][] = $artifact;
        }
    }

    /**
     * @return list<IndexedArtifact>
     */
    public function ofKind(string $kind): array
    {
        return $this->byKind[$kind] ?? [];
    }

    /**
     * @return list<IndexedArtifact>
     */
    public function ofKindInModule(string $kind, string $module): array
    {
        $out = [];
        foreach ($this->ofKind($kind) as $artifact) {
            if ($artifact->module === $module) {
                $out[] = $artifact;
            }
        }
        return $out;
    }

    /**
     * @return list<IndexedArtifact>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->byKind as $list) {
            foreach ($list as $artifact) {
                $out[] = $artifact;
            }
        }
        return $out;
    }
}
