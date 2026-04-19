<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Similarity;

/**
 * Walks the codebase and populates a {@see SimilarityIndex}.
 *
 * Kind → path-fragment mapping:
 *   - handler   → `/Application/Handler/PayloadHandler/`
 *   - listener  → `/Application/Handler/DomainListener/`
 *   - payload   → `/Application/Payload/Request/`
 *   - resource  → `/Application/Resource/Response/`
 *
 * Module resolution mirrors {@see \Semitexa\Dev\Ai\Convention\ConventionMiner}.
 * Both readers should eventually share a path→module helper, but the duplication
 * is small enough to defer until there's a third caller.
 */
final class SimilarityIndexBuilder
{
    private const KIND_BY_FRAGMENT = [
        '/Application/Handler/PayloadHandler/' => 'handler',
        '/Application/Handler/DomainListener/' => 'listener',
        '/Application/Payload/Request/'        => 'payload',
        '/Application/Resource/Response/'      => 'resource',
    ];

    /**
     * @param list<string> $rootsToScan
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $rootsToScan = ['src/modules', 'packages'],
    ) {}

    public function build(): SimilarityIndex
    {
        $artifacts = [];
        foreach ($this->collectFiles() as $absPath => $kind) {
            $module = $this->resolveModule($absPath);
            if ($module === null) {
                continue;
            }
            $artifact = $this->extractArtifact($absPath, $kind, $module);
            if ($artifact !== null) {
                $artifacts[] = $artifact;
            }
        }
        return new SimilarityIndex($artifacts);
    }

    /**
     * @return iterable<string, string> absolutePath => kind
     */
    private function collectFiles(): iterable
    {
        foreach ($this->rootsToScan as $root) {
            $abs = $this->projectRoot . '/' . trim($root, '/');
            if (!is_dir($abs)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iter as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $path = (string) $entry->getRealPath();
                if (!str_ends_with($path, '.php')) {
                    continue;
                }
                foreach (self::KIND_BY_FRAGMENT as $fragment => $kind) {
                    if (str_contains($path, $fragment)) {
                        yield $path => $kind;
                        break;
                    }
                }
            }
        }
    }

    private function resolveModule(string $absPath): ?string
    {
        $rel = ltrim(str_replace($this->projectRoot, '', $absPath), '/');
        if (preg_match('#^src/modules/([^/]+)/#', $rel, $m) === 1) {
            return $m[1];
        }
        if (preg_match('#^packages/semitexa-([^/]+)/src/#', $rel, $m) === 1) {
            $segs = preg_split('/[-_]+/', $m[1]) ?: [$m[1]];
            return implode('', array_map(static fn(string $s): string => ucfirst(strtolower($s)), $segs));
        }
        if (preg_match('#^packages/([^/]+)/src/#', $rel, $m) === 1) {
            $segs = preg_split('/[-_]+/', $m[1]) ?: [$m[1]];
            return implode('', array_map(static fn(string $s): string => ucfirst(strtolower($s)), $segs));
        }
        return null;
    }

    private function extractArtifact(string $absPath, string $kind, string $module): ?IndexedArtifact
    {
        $source = @file_get_contents($absPath);
        if ($source === false || $source === '') {
            return null;
        }

        $namespace = $this->readNamespace($source);
        $className = $this->readClassName($source);
        if ($className === null) {
            return null;
        }
        $fqcn = $namespace === '' ? $className : "{$namespace}\\{$className}";
        $relPath = ltrim(str_replace($this->projectRoot, '', $absPath), '/');

        $extras = match ($kind) {
            'payload'  => $this->readPayloadExtras($source),
            'listener' => $this->readListenerExtras($source),
            'handler'  => $this->readHandlerExtras($source),
            default    => [],
        };

        return new IndexedArtifact(
            kind: $kind,
            module: $module,
            className: $className,
            fqcn: $fqcn,
            relativePath: $relPath,
            extras: $extras,
        );
    }

    private function readNamespace(string $source): string
    {
        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $m) === 1) {
            return trim($m[1]);
        }
        return '';
    }

    private function readClassName(string $source): ?string
    {
        if (preg_match('/\b(?:final\s+)?(?:abstract\s+)?(?:readonly\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/', $source, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * Pull `path:` and `methods:` / `method:` from #[AsPayload(...)].
     *
     * @return array<string, string>
     */
    private function readPayloadExtras(string $source): array
    {
        $extras = [];
        if (preg_match('/#\[\s*AsPayload\b(.*?)\]/s', $source, $m) === 1) {
            $args = $m[1];
            if (preg_match("/path\s*:\s*['\"]([^'\"]+)['\"]/", $args, $pm) === 1) {
                $extras['route_path'] = $pm[1];
            }
            if (preg_match('/methods?\s*:\s*\[?\s*([^\],)]+)\s*\]?/', $args, $mm) === 1) {
                $extras['route_method'] = strtoupper(trim($mm[1], " '\"\t\n\r"));
            }
        }
        return $extras;
    }

    /**
     * Pull the event class ref out of #[AsEventListener(...)]. We keep the
     * short name (resolving against the file's `use` aliases) since the
     * detector cross-checks short names.
     *
     * @return array<string, string>
     */
    private function readListenerExtras(string $source): array
    {
        $extras = [];
        if (preg_match('/#\[\s*AsEventListener\b(.*?)\]/s', $source, $m) === 1) {
            $args = $m[1];
            if (preg_match('/([A-Za-z_][A-Za-z0-9_\\\\]*)::class/', $args, $cm) === 1) {
                $symbol = ltrim($cm[1], '\\');
                $aliases = $this->extractUseAliases($source);
                $extras['event_fqcn'] = $this->resolveSymbolToFqcn($symbol, $aliases);
            }
        }
        return $extras;
    }

    /**
     * Pull payload/resource class refs from #[AsPayloadHandler(...)].
     *
     * @return array<string, string>
     */
    private function readHandlerExtras(string $source): array
    {
        $extras = [];
        if (preg_match('/#\[\s*AsPayloadHandler\b(.*?)\]/s', $source, $m) === 1) {
            $args = $m[1];
            $aliases = $this->extractUseAliases($source);
            if (preg_match('/payload\s*:\s*([A-Za-z_][A-Za-z0-9_\\\\]*)::class/', $args, $pm) === 1) {
                $extras['payload_fqcn'] = $this->resolveSymbolToFqcn($pm[1], $aliases);
            }
            if (preg_match('/resource\s*:\s*([A-Za-z_][A-Za-z0-9_\\\\]*)::class/', $args, $rm) === 1) {
                $extras['resource_fqcn'] = $this->resolveSymbolToFqcn($rm[1], $aliases);
            }
        }
        return $extras;
    }

    /**
     * @return array<string, string>
     */
    private function extractUseAliases(string $source): array
    {
        $aliases = [];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $row) {
                $fqcn = ltrim($row[1], '\\');
                $alias = $row[2] ?? (strrpos($fqcn, '\\') !== false ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn);
                $aliases[$alias] = $fqcn;
            }
        }
        return $aliases;
    }

    /**
     * @param array<string, string> $aliases
     */
    private function resolveSymbolToFqcn(string $symbol, array $aliases): string
    {
        $symbol = ltrim($symbol, '\\');
        if (str_contains($symbol, '\\')) {
            return $symbol;
        }
        return $aliases[$symbol] ?? $symbol;
    }
}
