<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

/**
 * The paths the phpstan config of ai:verify excludes (its `excludePaths` list),
 * relative to the project root, and whether a changed file lies under one.
 *
 * phpstan analyses none of an excluded file, and a run given only such files
 * ends "No files found to analyse": a change to packages/semitexa-ultimate/src
 * (excluded on purpose, ultimate is gated by its own CI) made every ai:verify of
 * it incomplete, a verdict no change could turn into a pass
 * (tk-verify-schedules-di-phpstan-for-excluded-files).
 *
 * Read from the config itself, so the two cannot drift. It understands the
 * shape that config uses: a flat `excludePaths:` list of
 * `%currentWorkingDirectory%/<path>` entries, `(?)` allowed. Any other entry is
 * not read, and a file under it is still handed to phpstan, as before.
 */
final class PhpstanExcludedPaths
{
    private const CWD = '%currentWorkingDirectory%/';

    /** @param list<string> $paths project-relative, no trailing slash */
    private function __construct(public readonly array $paths)
    {
    }

    public static function forProject(string $projectRoot): self
    {
        return self::fromConfig(PhpstanRunner::configPathFor($projectRoot));
    }

    public static function fromConfig(string $configPath): self
    {
        $source = is_file($configPath) ? file_get_contents($configPath) : false;
        if ($source === false) {
            return new self([]);
        }

        $paths = [];
        $listIndent = null;
        foreach (preg_split('/\R/', $source) ?: [] as $line) {
            if ($listIndent === null) {
                if (preg_match('/^(\s*)excludePaths:\s*$/', $line, $match) === 1) {
                    $listIndent = strlen($match[1]);
                }
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if (strlen($line) - strlen(ltrim($line)) <= $listIndent) {
                break;
            }
            if (preg_match('/^-\s+[\'"]?' . preg_quote(self::CWD, '/') . '([^\'"]+?)[\'"]?(?:\s+\(\?\))?$/', $trimmed, $match) === 1) {
                $paths[] = rtrim($match[1], '/');
            }
        }

        return new self($paths);
    }

    public function excludes(string $relativePath): bool
    {
        // A --files value keeps its spelling: ./packages/... must match, and
        // packages/x/src/../../y must not (review, dev#278). A path that climbs
        // above the project root is never excluded.
        $normalized = self::normalize($relativePath);
        if ($normalized === null) {
            return false;
        }
        foreach ($this->paths as $path) {
            if ($normalized === $path || str_starts_with($normalized, $path . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * The path with its `.`, `..` and empty segments resolved; null when it is
     * absolute or climbs above the root, since neither is a project path
     * (an excluded one would skip a check that should run).
     */
    private static function normalize(string $path): ?string
    {
        if (str_starts_with($path, '/')) {
            return null;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
