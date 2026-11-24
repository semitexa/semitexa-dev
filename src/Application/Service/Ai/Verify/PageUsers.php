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

    /** @var \Closure(string): (string|false) */
    private readonly \Closure $read;

    /**
     * @param list<array{path: string, module: string, file?: string, template?: string}> $routes
     * @param (\Closure(string): (string|false))|null $read how a file is read; tests give one that fails
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $routes,
        ?\Closure $read = null,
    ) {
        $this->read = $read ?? static fn (string $path): string|false => @file_get_contents($path);
    }

    /**
     * The pages whose template chain holds $file: they render it. Only these are recorded as
     * showing a changed template ({@see PageCheckResult::settle()}).
     *
     * @return list<string> their paths
     */
    public function rendering(string $file): array
    {
        if (!str_ends_with($file, '.twig')) {
            return [];
        }
        $changed = $this->projectRoot . '/' . $file;

        return $this->pagesWhere(static fn (array $chain): bool => isset($chain[$changed]));
    }

    /**
     * The pages built on the package $file belongs to, when that package has no route: their
     * chain extends one of its layouts or links its asset. Worth opening for any of its files,
     * and proof of none: a page on a sibling layout does not render the changed one (review,
     * dev#312), and a stylesheet is judged by what the page loaded.
     *
     * @return list<string> their paths
     */
    public function builtOn(string $file): array
    {
        if (preg_match('#^((?:packages/semitexa-|vendor/semitexa/)([^/]+))/#', $file, $m) !== 1 || in_array('semitexa-' . $m[2], array_column($this->routes, 'module'), true)) {
            return [];
        }
        $uses = $this->usePattern($this->projectRoot . '/' . $m[1]);

        return $this->pagesWhere(static function (array $chain) use ($uses): bool {
            foreach ($chain as $source) {
                if (preg_match($uses, $source) === 1) {
                    return true;
                }
            }

            return false;
        });
    }

    /**
     * @param \Closure(array<string, string>): bool $matches
     * @return list<string>
     */
    private function pagesWhere(\Closure $matches): array
    {
        $pages = [];
        foreach ($this->routes as $route) {
            $templates = self::templatesOf($this->projectRoot, $route['module']);
            if ($templates === null || ($route['template'] ?? '') === '') {
                continue;
            }
            if ($matches($this->chains[$templates . "\0" . $route['template']] ??= $this->chain($templates, $route['template']))) {
                $pages[] = $route['path'];
            }
        }

        return $pages;
    }

    /**
     * The template and what it extends, includes, embeds or imports, as far as its own module holds it.
     *
     * @return array<string, string> file => its source, comments removed
     */
    private function chain(string $templates, string $template): array
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
            $chain[$path] = self::active($this->contentsOf($path));
            array_push($queue, ...self::dependencies($chain[$path]));
        }

        return $chain;
    }

    /** What a file holds. One that cannot be read fails the plan: a gate does not skip what it could not read. */
    private function contentsOf(string $path): string
    {
        $source = ($this->read)($path);
        if (!is_string($source)) {
            throw new \RuntimeException("The page check could not read {$path}: it cannot tell which pages show the change");
        }

        return $source;
    }

    /** The source Twig runs: no comment, no verbatim block. */
    private static function active(string $source): string
    {
        return (string) preg_replace(['~\{#.*?#\}~s', '~\{%-?\s*(verbatim|raw)\s*-?%\}.*?\{%-?\s*end\1\s*-?%\}~s'], '', $source);
    }

    /**
     * The templates a source names in a real operation: the extends, include, embed, import,
     * from and use tags, and the include() function. A path quoted anywhere else (a comment,
     * a variable, an attribute) is no dependency (review, dev#312).
     *
     * @return list<string>
     */
    private static function dependencies(string $active): array
    {
        $named = [];
        preg_match_all('~\{%-?\s*(?:extends|include|embed|import|from|use)\s+(.*?)-?%\}~s', $active, $tags);
        foreach ($tags[1] as $expression) {
            // The template expression only: what follows is the tag's own arguments.
            $expression = (string) preg_split('~\s(?:with|only|ignore|import|as)\b~', $expression, 2)[0];
            preg_match_all('~[\'"](@[\w.-]+/[^\'"]+\.twig)[\'"]~', $expression, $literals);
            array_push($named, ...$literals[1]);
        }
        preg_match_all('~\{[{%](?:(?![%}]\}).)*?\binclude\(\s*\[?\s*[\'"](@[\w.-]+/[^\'"]+\.twig)[\'"]~s', $active, $calls);

        return [...$named, ...$calls[1]];
    }

    /** A pattern for how a template builds on the package: extending its layout, or linking its asset. */
    private function usePattern(string $package): string
    {
        // Metadata that is not there says nothing; metadata that is there has to be readable.
        $meta = is_file($package . '/composer.json') ? $this->json($package . '/composer.json')['extra']['semitexa-module'] ?? [] : [];
        $meta = is_array($meta) ? $meta : [];
        $alternatives = ['project-layouts-' . preg_quote(basename($package), '~')];
        foreach ([$meta['template_alias'] ?? null, is_string($meta['name'] ?? null) ? 'project-layouts-' . $meta['name'] : null] as $alias) {
            if (is_string($alias) && $alias !== '') {
                $alternatives[] = preg_quote($alias, '~');
            }
        }
        // Built on its layout, not merely including a partial of it: the layout is what links the stylesheets.
        $alternatives = ['\{%-?\s*extends\s+[\'"]@(?:' . implode('|', $alternatives) . ')/'];
        foreach (['/src/Application/Static/assets.json', '/Application/Static/assets.json'] as $manifest) {
            $assets = is_file($package . $manifest) ? $this->json($package . $manifest) : [];
            if (is_string($assets['module'] ?? null) && $assets['module'] !== '') {
                $alternatives[] = '\basset\([^)]*[\'"]' . preg_quote($assets['module'], '~') . '[\'"]';
            }
        }

        return '~' . implode('|', $alternatives) . '~';
    }

    /** @return array<mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode($this->contentsOf($path), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("The page check could not read {$path} as JSON: it cannot tell which pages show the change");
        }

        return $decoded;
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
