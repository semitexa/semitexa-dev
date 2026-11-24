<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The pages that render a changed template or use a changed package, for
 * {@see PageCheckTargets}: read from the templates, starting at the one each
 * route's resource names and following what it extends and includes inside its
 * own module.
 *
 * Two cases, both measured (tk-verify-page-check-off-site-redirect-incomplete):
 *
 *  - a template: the pages whose chain holds that file. A semitexa-demo template
 *    opened the package's eight shortest paths, its blog, and never its own page;
 *  - a file of a package with no route (semitexa-showcase-kit: layouts and
 *    stylesheets): the pages whose chain extends a layout of that package
 *    (`extends '@alias/…'`) or links its asset (`asset('…', 'showcase-kit')`).
 *    A change to its nav.css opened the project's pages, none of which loads it.
 *    What a module requires in composer.json does not say what a page loads
 *    (semitexa-os requires the theme and links none of its stylesheets), and
 *    neither does including one partial: a demo page includes the theme's
 *    switcher and links no skin.
 */
final class PageUsers
{
    /** @var array<string, array<string, string>> a route's template chain, read once for the plan */
    private array $chains = [];

    /** @param list<array{path: string, module: string, file?: string, template?: string}> $routes */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $routes,
    ) {}

    /** @return list<string> the paths of the pages that can show a change to $file */
    public function showing(string $file): array
    {
        $changed = str_ends_with($file, '.twig') ? $this->projectRoot . '/' . $file : null;
        $package = preg_match('#^((?:packages/semitexa-|vendor/semitexa/)([^/]+))/#', $file, $m) === 1 ? 'semitexa-' . $m[2] : null;
        $uses = $package !== null && !in_array($package, array_column($this->routes, 'module'), true) ? self::usePattern($this->projectRoot . '/' . $m[1]) : null;
        if ($changed === null && $uses === null) {
            return [];
        }

        $pages = [];
        foreach ($this->routes as $route) {
            $templates = self::templatesOf($this->projectRoot, $route['module']);
            if ($templates === null || ($route['template'] ?? '') === '') {
                continue;
            }
            $chain = $this->chains[$templates . "\0" . $route['template']] ??= self::chain($templates, $route['template']);
            foreach ($chain as $path => $source) {
                if ($path === $changed || ($uses !== null && preg_match($uses, $source) === 1)) {
                    $pages[] = $route['path'];
                    break;
                }
            }
        }

        return $pages;
    }

    /**
     * The template and what it extends, includes or embeds, as far as its own module holds it.
     *
     * @return array<string, string> file => its source
     */
    private static function chain(string $templates, string $template): array
    {
        $alias = strstr($template, '/', true);
        $chain = [];
        $queue = [$template];
        while ($queue !== []) {
            $name = (string) array_shift($queue);
            $path = $templates . substr($name, strlen((string) $alias));
            if (strstr($name, '/', true) !== $alias || isset($chain[$path]) || str_contains($name, '..') || !is_file($path)) {
                continue;
            }
            $chain[$path] = (string) file_get_contents($path);
            preg_match_all('~[\'"](@[\w.-]+/[^\'"]+\.twig)[\'"]~', $chain[$path], $named);
            array_push($queue, ...$named[1]);
        }

        return $chain;
    }

    /** A pattern for how a template builds on the package: extending its layout, or linking its asset. */
    private static function usePattern(string $package): string
    {
        $composer = json_decode((string) @file_get_contents($package . '/composer.json'), true);
        $meta = is_array($composer) && is_array($composer['extra']['semitexa-module'] ?? null) ? $composer['extra']['semitexa-module'] : [];
        $alternatives = ['project-layouts-' . preg_quote(basename($package), '~')];
        foreach ([$meta['template_alias'] ?? null, is_string($meta['name'] ?? null) ? 'project-layouts-' . $meta['name'] : null] as $alias) {
            if (is_string($alias) && $alias !== '') {
                $alternatives[] = preg_quote($alias, '~');
            }
        }
        // Built on its layout, not merely including a partial of it: the layout is what links the stylesheets.
        $alternatives = ['extends\s+[\'"]@(?:' . implode('|', $alternatives) . ')/'];
        foreach (['/src/Application/Static/assets.json', '/Application/Static/assets.json'] as $manifest) {
            $assets = json_decode((string) @file_get_contents($package . $manifest), true);
            if (is_array($assets) && is_string($assets['module'] ?? null) && $assets['module'] !== '') {
                $alternatives[] = 'asset\([^)]*[\'"]' . preg_quote($assets['module'], '~') . '[\'"]';
            }
        }

        return '~' . implode('|', $alternatives) . '~';
    }

    private static function templatesOf(string $projectRoot, string $module): ?string
    {
        if (preg_match('#^[A-Za-z0-9_.-]+$#', $module) !== 1) {
            return null;
        }
        $roots = str_starts_with($module, 'semitexa-')
            ? ['/packages/' . $module, '/vendor/semitexa/' . substr($module, strlen('semitexa-'))]
            : ['/src/modules/' . $module];
        foreach ($roots as $root) {
            foreach (['/src/Application/View/templates', '/Application/View/templates'] as $templates) {
                if (is_dir($projectRoot . $root . $templates)) {
                    return $projectRoot . $root . $templates;
                }
            }
        }

        return null;
    }
}
