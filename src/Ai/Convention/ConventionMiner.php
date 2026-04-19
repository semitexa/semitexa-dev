<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Convention;

/**
 * Walks the codebase, finds handler classes, and extracts injection patterns
 * keyed by module. The result is the input to {@see ConventionStore}.
 *
 * Static analysis (token_get_all + regex) — never autoloads classes — so the
 * miner is safe to run during boot or in CI without triggering side effects.
 *
 * "Module" resolution rules:
 *   - `src/modules/<Name>/...`         → Name
 *   - `packages/<pkg>/src/...`         → pkg-name (e.g. semitexa-ssr → Ssr)
 * Files outside both layouts are ignored — they aren't agent-targetable.
 */
final class ConventionMiner
{
    private const HANDLER_GLOB_FRAGMENTS = [
        '/Application/Handler/PayloadHandler/',
    ];

    /**
     * @param list<string> $rootsToScan Repo-relative roots to walk (typically ['src/modules', 'packages']).
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $rootsToScan = ['src/modules', 'packages'],
    ) {}

    /**
     * @return array<string, ModuleConventions>
     */
    public function mineAll(): array
    {
        $byModule = [];
        foreach ($this->collectHandlerFiles() as $file) {
            $module = $this->resolveModule($file);
            if ($module === null) {
                continue;
            }

            $injections = $this->extractInjections($file);
            if ($injections === []) {
                $byModule[$module] ??= ['count' => 0, 'rows' => []];
                $byModule[$module]['count']++;
                continue;
            }

            $byModule[$module] ??= ['count' => 0, 'rows' => []];
            $byModule[$module]['count']++;
            foreach ($injections as $row) {
                $key = $row['attribute'] . '|' . $row['type'] . '|' . $row['property'];
                if (!isset($byModule[$module]['rows'][$key])) {
                    $byModule[$module]['rows'][$key] = $row + ['frequency' => 0];
                }
                $byModule[$module]['rows'][$key]['frequency']++;
            }
        }

        $out = [];
        foreach ($byModule as $module => $data) {
            $injections = [];
            foreach ($data['rows'] as $row) {
                $injections[] = new HandlerInjection(
                    type: $row['type'],
                    shortType: $row['short_type'],
                    attribute: $row['attribute'],
                    propertyName: $row['property'],
                    frequency: $row['frequency'],
                );
            }
            usort($injections, static fn(HandlerInjection $a, HandlerInjection $b): int => $b->frequency <=> $a->frequency);

            $out[$module] = new ModuleConventions(
                module: $module,
                handler_injections: $injections,
                handlers_sampled: $data['count'],
            );
        }
        ksort($out);
        return $out;
    }

    /**
     * @return list<string>
     */
    private function collectHandlerFiles(): array
    {
        $files = [];
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
                foreach (self::HANDLER_GLOB_FRAGMENTS as $fragment) {
                    if (str_contains($path, $fragment)) {
                        $files[] = $path;
                        break;
                    }
                }
            }
        }
        return $files;
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

    /**
     * Token-walk a single handler file and extract every property declaration
     * that carries an `#[InjectAsReadonly]`, `#[InjectAsMutable]`, or
     * `#[InjectAsFactory]` attribute. Returns one row per property.
     *
     * @return list<array{attribute: string, type: string, short_type: string, property: string}>
     */
    private function extractInjections(string $absPath): array
    {
        $source = @file_get_contents($absPath);
        if ($source === false || $source === '') {
            return [];
        }

        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return [];
        }

        $useAliases = $this->extractUseAliases($source);
        $rows = [];
        $pendingAttribute = null;
        $depth = 0;

        for ($i = 0, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];

            if ($tok === '{') {
                $depth++;
                continue;
            }
            if ($tok === '}') {
                $depth--;
                continue;
            }

            // Track #[Inject*] attributes on the next property.
            if (is_array($tok) && $tok[0] === T_ATTRIBUTE) {
                $captured = $this->captureAttributeName($tokens, $i);
                if ($captured !== null && in_array($captured['name'], ['InjectAsReadonly', 'InjectAsMutable', 'InjectAsFactory'], true)) {
                    $pendingAttribute = $captured['name'];
                }
                $i = $captured['endIndex'] ?? $i;
                continue;
            }

            // Property declarations only happen at class-body depth (=1).
            if ($depth !== 1 || $pendingAttribute === null) {
                continue;
            }

            if (is_array($tok) && in_array($tok[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_STATIC], true)) {
                $row = $this->captureProperty($tokens, $i);
                if ($row !== null) {
                    $resolvedType = $this->resolveTypeAlias($row['type'], $useAliases);
                    $rows[] = [
                        'attribute'  => $pendingAttribute,
                        'type'       => $resolvedType,
                        'short_type' => $this->shortName($resolvedType),
                        'property'   => $row['property'],
                    ];
                    $pendingAttribute = null;
                }
            }
        }

        return $rows;
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array{name: string, endIndex: int}|null
     */
    private function captureAttributeName(array $tokens, int $startIndex): ?array
    {
        $name = '';
        $endIndex = $startIndex;
        for ($i = $startIndex + 1, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if ($tok === ']') {
                $endIndex = $i;
                break;
            }
            if (is_array($tok) && in_array($tok[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $tok[1];
            }
            if ($tok === '(') {
                break;
            }
        }
        $name = ltrim((string) $name, '\\');
        $base = strrpos($name, '\\') !== false ? substr($name, strrpos($name, '\\') + 1) : $name;
        if ($base === '') {
            return null;
        }

        // Skip past the closing bracket if we broke on '(' — find matching ']'.
        if ($endIndex === $startIndex) {
            $bracketDepth = 1;
            for ($j = $startIndex + 1, $n = count($tokens); $j < $n; $j++) {
                if ($tokens[$j] === '[') {
                    $bracketDepth++;
                } elseif ($tokens[$j] === ']') {
                    $bracketDepth--;
                    if ($bracketDepth === 0) {
                        $endIndex = $j;
                        break;
                    }
                }
            }
        }

        return ['name' => $base, 'endIndex' => $endIndex];
    }

    /**
     * @param array<int, mixed> $tokens
     * @return array{type: string, property: string}|null
     */
    private function captureProperty(array $tokens, int $startIndex): ?array
    {
        $type = '';
        $property = null;
        for ($i = $startIndex + 1, $n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if ($tok === ';' || $tok === '=' || $tok === '{') {
                break;
            }
            if (is_array($tok)) {
                [$id, $text] = [$tok[0], $tok[1]];
                if (in_array($id, [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_STATIC, T_WHITESPACE], true)) {
                    continue;
                }
                if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR, T_ARRAY], true)) {
                    $type .= $text;
                    continue;
                }
                if ($id === T_VARIABLE) {
                    $property = ltrim($text, '$');
                    break;
                }
                if ($id === T_NULLABLE_TYPE || $text === '?') {
                    $type .= '?';
                }
            } elseif ($tok === '?') {
                $type .= '?';
            }
        }

        if ($property === null || trim($type) === '') {
            return null;
        }
        return ['type' => trim($type), 'property' => $property];
    }

    /**
     * @return array<string, string>
     */
    private function extractUseAliases(string $source): array
    {
        $aliases = [];
        if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $source, $matches, PREG_SET_ORDER) !== false) {
            foreach ($matches as $m) {
                $fqcn = ltrim($m[1], '\\');
                $alias = $m[2] ?? (strrpos($fqcn, '\\') !== false ? substr($fqcn, strrpos($fqcn, '\\') + 1) : $fqcn);
                $aliases[$alias] = $fqcn;
            }
        }
        return $aliases;
    }

    /**
     * @param array<string, string> $aliases
     */
    private function resolveTypeAlias(string $declared, array $aliases): string
    {
        $nullable = '';
        $bare = $declared;
        if (str_starts_with($bare, '?')) {
            $nullable = '?';
            $bare = substr($bare, 1);
        }
        if (str_starts_with($bare, '\\')) {
            return $nullable . ltrim($bare, '\\');
        }
        $head = strpos($bare, '\\') !== false ? substr($bare, 0, strpos($bare, '\\')) : $bare;
        if (isset($aliases[$head])) {
            $rest = substr($bare, strlen($head));
            return $nullable . $aliases[$head] . $rest;
        }
        return $nullable . $bare;
    }

    private function shortName(string $type): string
    {
        $bare = ltrim($type, '?');
        return strrpos($bare, '\\') !== false ? substr($bare, strrpos($bare, '\\') + 1) : $bare;
    }
}
