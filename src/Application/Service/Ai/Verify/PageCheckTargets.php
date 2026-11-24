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
 * Which pages: public GET routes with no parameter. First the pages whose
 * templates are known to show the file ({@see PageUsers}): those that render a
 * changed template, or use a package that has no route. When none is known, a
 * file of a local module picks that module's pages, a package's file that
 * package's pages; only one whose package has no page of its own (the theme, a
 * layout) and a project file outside a module pick the project's pages. A file only a dev surface shows
 * (the graph viewer's stylesheet) picks no page: {@see DevSurfaceAssets}. Capped: a browser is the dearest check there is, so not at
 * minimal scope either.
 */
final class PageCheckTargets
{
    public const LIMIT = 8;

    /**
     * @param list<ChangedFile> $changedFiles
     * @param list<array{path: string, module: string, file?: string, template?: string}> $routes public parameterless GET routes, as discovered
     * @param PageUsers|null $users what the templates say about which pages to open: a choice, never proof that one showed the file
     *
     * @return list<VerificationTarget>
     */
    public static function of(array $changedFiles, string $effectiveScope, array $routes, ?PageUsers $users = null): array
    {
        if ($effectiveScope === VerificationPlan::SCOPE_MINIMAL) {
            return [];
        }

        /** @var array<string, list<string>> $pages path => the files that put it here */
        $pages = [];
        $declaredIn = [];
        foreach ($routes as $route) {
            $declaredIn[$route['path']] = $route['file'] ?? '';
        }
        /** @var array<string, true> $first the pages the templates chose, not a fallback */
        $first = [];
        /** @var array<string, list<string>> $devSurfaces surface => the files it alone shows */
        $devSurfaces = [];
        foreach ($changedFiles as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED || !in_array($file->kind, [ChangedFile::KIND_TEMPLATE, ChangedFile::KIND_STYLESHEET], true)) {
                continue;
            }
            $surface = DevSurfaceAssets::surfaceOf($file->path);
            if ($surface !== null) {
                $devSurfaces[$surface][] = $file->path;
                continue;
            }
            [$paths, $chosen] = self::pagesOf($file->path, $routes, $users);
            foreach ($paths as $path) {
                $pages[$path][] = $file->path;
                $first += $chosen ? [$path => true] : [];
            }
        }

        $targets = [];
        // The pages the templates chose before any file's fallback pages: eight of those must not push the exact page out.
        foreach (array_slice(array_intersect_key($pages, $first) + $pages, 0, self::LIMIT, true) as $path => $files) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_PAGE,
                id: "page:{$path}",
                reason: 'a changed template or stylesheet shows here — opened in light and dark in the e2e-runner, against the running app',
                triggeredBy: array_values(array_unique($files)),
                commandInput: ['path' => (string) $path, 'declared_in' => $declaredIn[$path] ?? ''],
            );
        }

        return [...$targets, ...DevSurfaceAssets::targets($devSurfaces)];
    }

    /**
     * @param list<array{path: string, module: string, file?: string, template?: string}> $routes
     *
     * @return array{list<string>, bool} the paths, and whether the templates chose them (a choice of what to open, never proof of what rendered)
     */
    private static function pagesOf(string $file, array $routes, ?PageUsers $users): array
    {
        $usable = array_values(array_filter($routes, static fn (array $r): bool => self::isPage($r['path'])));
        $of = static fn (?string $module): array => $module === null ? [] : array_filter($usable, static fn (array $r): bool => $r['module'] === $module);
        $package = preg_match('#^(?:packages/semitexa-|vendor/semitexa/)([^/]+)/#', $file, $p) === 1 ? 'semitexa-' . $p[1] : null;
        $module = preg_match('#^src/modules/([^/]+)/#', $file, $m) === 1 ? $m[1] : null;
        // Its own pages first: six other modules' pages passing said nothing about a demo template (review dev#216).
        $mine = $of($module ?? $package);
        // The pages whose templates hold the changed one, or use its package when that has no route:
        // a package's eight shortest paths were its blog, which answers from another site.
        $in = static fn (array $paths): array => array_filter($usable, static fn (array $r): bool => in_array($r['path'], $paths, true));
        $rendering = $users !== null ? $in($users->rendering($file)) : [];
        $chosen = $rendering !== [] || $users === null ? $rendering : $in($users->builtOn($file));
        $mine = $chosen !== [] ? $chosen : $mine;
        if ($mine === [] && $module === null) {
            $mine = array_filter($usable, static fn (array $r): bool => !str_starts_with($r['module'], 'semitexa-'));
        }
        $paths = array_values(array_unique(array_map(static fn (array $r): string => $r['path'], $mine)));
        // The shortest paths first: a section's front page shows more of its layout than a leaf.
        usort($paths, static fn (string $a, string $b): int => [substr_count($a, '/'), strlen($a), $a] <=> [substr_count($b, '/'), strlen($b), $b]);

        return [$paths, $chosen !== []];
    }

    /** A page a browser can open as it is: no parameter, not a dev surface, not an API. */
    private static function isPage(string $path): bool
    {
        return $path !== '' && !str_contains($path, '{') && preg_match('#^/(?:__|api(?:/|$))#', $path) !== 1;
    }
}
