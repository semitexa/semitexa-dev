<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The pages a changed template or stylesheet shows on, one target each.
 *
 * On 2026-11-07 every test passed on a page a person called awful: a base
 * layout linked no skin, and a token that existed nowhere put light text on
 * white in dark mode. No check opened the page. `bin/semitexa ai:verify` asks
 * for this list (`--list-pages`), opens each page in light and dark in the
 * e2e-runner, and files what it saw where {@see PageCheckResult} reads it.
 *
 * Which pages: public GET routes with no parameter. A file of a local module
 * picks that module's pages, a package's file that package's pages; only one
 * whose package has no page of its own (the theme, a layout) and a project
 * file outside a module pick the project's pages. Capped: a browser is the dearest check there is, so not at
 * minimal scope either.
 */
final class PageCheckTargets
{
    public const LIMIT = 8;

    /**
     * @param list<ChangedFile> $changedFiles
     * @param list<array{path: string, module: string}> $routes public parameterless GET routes, as discovered
     *
     * @return list<VerificationTarget>
     */
    public static function of(array $changedFiles, string $effectiveScope, array $routes): array
    {
        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL) {
            return [];
        }

        /** @var array<string, list<string>> $pages path => the files that put it here */
        $pages = [];
        foreach ($changedFiles as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED || !in_array($file->kind, [ChangedFile::KIND_TEMPLATE, ChangedFile::KIND_STYLESHEET], true)) {
                continue;
            }
            foreach (self::pagesOf($file->path, $routes) as $path) {
                $pages[$path][] = $file->path;
            }
        }

        $targets = [];
        foreach (array_slice($pages, 0, self::LIMIT, true) as $path => $files) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_PAGE,
                id: "page:{$path}",
                reason: 'a changed template or stylesheet shows here — opened in light and dark in the e2e-runner, against the running app',
                triggeredBy: array_values(array_unique($files)),
                commandInput: ['path' => (string) $path],
            );
        }

        return $targets;
    }

    /**
     * @param list<array{path: string, module: string}> $routes
     *
     * @return list<string>
     */
    private static function pagesOf(string $file, array $routes): array
    {
        $usable = array_values(array_filter($routes, static fn (array $r): bool => self::isPage($r['path'])));
        if (preg_match('#^src/modules/([^/]+)/#', $file, $m) === 1) {
            $mine = array_filter($usable, static fn (array $r): bool => $r['module'] === $m[1]);
        } else {
            $package = preg_match('#^(?:packages/semitexa-|vendor/semitexa/)([^/]+)/#', $file, $p) === 1 ? 'semitexa-' . $p[1] : null;
            // Its own pages first: six other modules' pages passing said nothing about a demo template (review dev#216).
            $mine = $package !== null ? array_filter($usable, static fn (array $r): bool => $r['module'] === $package) : [];
            if ($mine === []) {
                $mine = array_filter($usable, static fn (array $r): bool => !str_starts_with($r['module'], 'semitexa-'));
            }
        }
        $paths = array_values(array_unique(array_map(static fn (array $r): string => $r['path'], $mine)));
        // The shortest paths first: a section's front page shows more of its layout than a leaf.
        usort($paths, static fn (string $a, string $b): int => [substr_count($a, '/'), strlen($a), $a] <=> [substr_count($b, '/'), strlen($b), $b]);

        return $paths;
    }

    /** A page a browser can open as it is: no parameter, not a dev surface, not an API. */
    private static function isPage(string $path): bool
    {
        return $path !== '' && !str_contains($path, '{') && preg_match('#^/(?:__|api(?:/|$))#', $path) !== 1;
    }
}
