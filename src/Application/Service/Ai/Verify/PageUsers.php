<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The pages worth opening for a changed template or a changed package, for
 * {@see PageCheckTargets}: read from the templates, starting at the one each
 * route's resource names and following what it extends and includes inside its
 * own module.
 *
 * The static chain chooses pages; it is never proof. What Twig renders is
 * decided when it runs (`include ['a', 'b']` takes the first that exists, a
 * name may be a variable), so nothing here excuses a page: only a stylesheet
 * seen loading excuses an off-site page ({@see PageCheckResult::settle()}).
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
 *
 * A page also renders the templates of the slots registered under its handle
 * (`#[AsSlotResource]`, shown by `layout_slot()` and `layout_slot_deferred()`):
 * nothing in its own template names them, so the route carries them
 * ({@see PageRoutes::slotTemplatesOf()}) and each starts a chain of its own.
 * A semitexa-demo deferred slot's template matched no page and opened the blog
 * (tk-verify-page-targeting-misses-slot-templates).
 */
final class PageUsers
{
    /** @var array<string, array<string, list<array{string, list<string>}>>> a route's template chain, read once for the plan */
    private array $chains = [];

    /** @var array<string, list<string>> template root => the namespaces that name it */
    private array $own = [];

    /** @var \Closure(string): (string|false) */
    private readonly \Closure $read;

    /**
     * @param list<array{path: string, module: string, file?: string, template?: string, slots?: list<string>}> $routes
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
     * The pages whose template chain names $file, from the page's own template or from a slot
     * registered under its handle: the ones to open for it, before any fallback.
     * A choice, not proof that the page rendered it.
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
        [$aliases, $assetModules] = $this->namesOf($this->projectRoot . '/' . $m[1]);

        return $this->pagesWhere(static function (array $chain) use ($aliases, $assetModules): bool {
            foreach ($chain as $operations) {
                foreach ($operations as [$operation, $literals]) {
                    // Built on its layout, not merely including a partial of it: the layout is what links the stylesheets.
                    $named = $operation === 'extends' ? array_map(static fn (string $name): string => (string) strstr($name, '/', true), $literals) : ($operation === 'asset()' ? $literals : []);
                    if (array_intersect($named, $operation === 'extends' ? $aliases : $assetModules) !== []) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    /**
     * @param \Closure(array<string, list<array{string, list<string>}>>): bool $matches
     * @return list<string>
     */
    private function pagesWhere(\Closure $matches): array
    {
        $pages = [];
        foreach ($this->routes as $route) {
            $templates = self::templatesOf($this->projectRoot, $route['module']);
            // The page's template first, then its slots': each is rendered on that page.
            $starts = array_values(array_filter([$route['template'] ?? '', ...($route['slots'] ?? [])], static fn (string $name): bool => $name !== ''));
            if ($templates === null || $starts === []) {
                continue;
            }
            // Only a name in the module's own namespace is a file under its template root: a slot
            // template of another module, read there, matched an unrelated file of the same
            // relative path and replaced the fallback with a false match (review, dev#313).
            $own = $this->own[$templates] ??= $this->namesOf((string) preg_replace('~(?:/src)?/Application/View/templates$~', '', $templates))[0];
            $starts = array_values(array_filter($starts, static fn (string $name): bool => in_array(strstr($name, '/', true), $own, true)));
            if ($starts === []) {
                continue;
            }
            if ($matches($this->chains[$templates . "\0" . implode("\0", $starts)] ??= $this->chain($templates, $starts, $own))) {
                $pages[] = $route['path'];
            }
        }

        return $pages;
    }

    /**
     * The templates a page starts from and what they extend, include, embed or import, as far as its own module holds it.
     *
     * @param non-empty-list<string> $starts the page's template and its slots', each in one of $own
     * @param list<string> $own the namespaces that name the module's own template root
     * @return array<string, list<array{string, list<string>}>> file => its real operations
     */
    private function chain(string $templates, array $starts, array $own): array
    {
        $chain = [];
        $queue = $starts;
        while ($queue !== []) {
            $name = (string) array_shift($queue);
            $alias = (string) strstr($name, '/', true);
            $path = $templates . substr($name, strlen($alias));
            if (!in_array($alias, $own, true) || isset($chain[$path]) || str_contains($name, '..') || !is_file($path)) {
                continue;
            }
            $chain[$path] = self::operations(self::active($this->contentsOf($path)));
            foreach ($chain[$path] as [$operation, $literals]) {
                array_push($queue, ...($operation === 'asset()' ? [] : preg_grep('~^@[\w.-]+/.+\.twig$~', $literals)));
            }
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
     * The real operations of a source and the string literals each names: the extends, include,
     * embed, import, from and use tags, and the include() and asset() functions. Read from the
     * `{% %}` and `{{ }}` blocks with the contents of their strings masked, so that a path quoted
     * anywhere else is no operation: in a comment, in a variable, or in a string Twig only
     * prints, `{{ "include('…')" }}` (review, dev#312).
     *
     * @return list<array{string, list<string>}>
     */
    private static function operations(string $active): array
    {
        $operations = [];
        $length = strlen($active);
        $offset = 0;
        while (preg_match('~\{[{%]~', $active, $open, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $close = $open[0][0] === '{{' ? '}}' : '%}';
            $start = $open[0][1] + 2;
            $masked = '';
            for ($i = $start; $i < $length && substr($active, $i, 2) !== $close; $i = $start + strlen($masked)) {
                $char = $active[$i];
                if ($char !== '"' && $char !== "'") {
                    $masked .= $char;
                    continue;
                }
                for ($end = $i + 1; $end < $length && $active[$end] !== $char; $end += $active[$end] === '\\' ? 2 : 1);
                $end = min($end, $length);
                $masked .= $char . str_repeat(' ', $end - $i - 1) . ($end < $length ? $char : '');
            }
            $body = substr($active, $start, strlen($masked));
            $offset = $start + strlen($masked) + 2;

            if ($close === '%}' && preg_match('~^-?\s*(extends|include|embed|import|from|use)\s~', $masked, $tag) === 1) {
                // The template expression only: what follows is the tag's own arguments.
                $to = preg_match('~\s(?:with|only|ignore|import|as)\b~', $masked, $rest, PREG_OFFSET_CAPTURE, strlen($tag[0])) === 1 ? $rest[0][1] : strlen($masked);
                $operations[] = [$tag[1], self::literals($body, $masked, 0, $to)];
            }
            preg_match_all('~(?<![.\w])(include|asset)\(\s*~', $masked, $calls, PREG_OFFSET_CAPTURE); // not o.include(): a method
            foreach ($calls[1] as $n => [$function]) {
                $from = $calls[0][$n][1] + strlen($calls[0][$n][0]);
                // include(): its first argument, one template or a list of them. asset(): every argument.
                $ends = $function === 'asset' ? ')' : (($masked[$from] ?? '') === '[' ? ']' : ',)');
                $operations[] = [$function . '()', self::literals($body, $masked, $from, $from + strcspn($masked, $ends, $from))];
            }
        }

        return $operations;
    }

    /**
     * The string literals of a block between two offsets, found in its masked form and read from the real one.
     *
     * @return list<string>
     */
    private static function literals(string $body, string $masked, int $from, int $to): array
    {
        preg_match_all('~([\'"])( *)\1~', substr($masked, $from, max(0, $to - $from)), $strings, PREG_OFFSET_CAPTURE);

        return array_map(static fn (array $string): string => substr($body, $from + $string[1], strlen($string[0])), $strings[2]);
    }

    /**
     * The names a template uses the package by: its template namespaces, and its asset modules.
     *
     * @return array{list<string>, list<string>}
     */
    private function namesOf(string $package): array
    {
        // Metadata that is not there says nothing; metadata that is there has to be readable.
        $meta = is_file($package . '/composer.json') ? $this->json($package . '/composer.json')['extra']['semitexa-module'] ?? [] : [];
        $meta = is_array($meta) ? $meta : [];
        // The bare alias too, by directory and by name: the template loader takes `@Hello/…` as it
        // takes `@project-layouts-Hello/…` (review, dev#313: without it such a page lost its match).
        $aliases = ['@project-layouts-' . basename($package), '@' . basename($package)];
        foreach ([$meta['template_alias'] ?? null, is_string($meta['name'] ?? null) ? 'project-layouts-' . $meta['name'] : null, is_string($meta['name'] ?? null) ? $meta['name'] : null] as $alias) {
            if (is_string($alias) && $alias !== '') {
                $aliases[] = '@' . $alias;
            }
        }
        $assetModules = [];
        foreach (['/src/Application/Static/assets.json', '/Application/Static/assets.json'] as $manifest) {
            $assets = is_file($package . $manifest) ? $this->json($package . $manifest) : [];
            if (is_string($assets['module'] ?? null) && $assets['module'] !== '') {
                $assetModules[] = $assets['module'];
            }
        }

        return [array_values(array_unique($aliases)), $assetModules];
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
