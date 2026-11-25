<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Attribute\AsResource;
use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\ModuleRegistry;
use Semitexa\Ssr\Application\Service\Layout\LayoutSlotRegistry;

/**
 * The routes a browser can open as they are, for {@see PageCheckTargets}:
 * GET, public, no parameter in the path, producing HTML when they say what they
 * produce, and not one whose last segment names an action: a GET that logs out,
 * starts a sign-in or submits does it on every check, twice (review dev#216). Each named by the module it belongs
 * to, as routes:list names it ("semitexa-demo" for a package, the module's own
 * name for one under src/modules).
 */
final class PageRoutes
{
    private const ACTIONS = '#/(?:log-?out|sign-?out|log-?in|sign-?in|start|callback|submit|delete|remove|unsubscribe|confirm|verify|reset|export|download)$#i';

    /** @return list<array{path: string, module: string, file: string, template: string, slots: list<string>, sources: list<string>}> */
    public static function of(?AttributeDiscovery $discovery, ?ModuleRegistry $modules): array
    {
        if ($discovery === null) {
            return [];
        }

        $routes = [];
        // Enriched: with the response class and the handlers, whose files sourcesOf() reads.
        foreach ($discovery->getEnrichedRoutes() as $route) {
            if (self::isPage($route)) {
                $class = (string) ($route['class'] ?? '');
                $routes[] = [
                    'path' => (string) $route['path'],
                    'module' => $modules?->getModuleNameForClass($class) ?? 'project',
                    // Where the route is declared: a page check tells a server that has
                    // not loaded it yet from a route that answers 404 (tk-page-check-stale-route).
                    'file' => class_exists($class) ? (string) (new \ReflectionClass($class))->getFileName() : '',
                    // What the page renders: a changed template opens the pages that render it,
                    // not its package's shortest paths (tk-verify-page-check-off-site-redirect-incomplete).
                    'template' => self::templateOf($route['responseClass'] ?? $route['responseWith'] ?? null),
                    // And what its slots render: a slot's template is named by the slot, not by the page's
                    // template (tk-verify-page-targeting-misses-slot-templates).
                    'slots' => self::slotTemplatesOf($route['responseClass'] ?? $route['responseWith'] ?? null),
                    // And the PHP that answers it: a handler hands its resource a template by name, which no
                    // template and no attribute says (tk-verify-page-plan-misses-the-page-a-demo-template-renders).
                    'sources' => self::sourcesOf($route),
                ];
            }
        }

        return $routes;
    }

    /** The template the route's resource names in #[AsResource], '' when it names none. */
    public static function templateOf(mixed $resource): string
    {
        if (!is_string($resource) || !class_exists($resource)) {
            return '';
        }
        foreach ((new \ReflectionClass($resource))->getAttributes(AsResource::class) as $attribute) {
            return (string) $attribute->newInstance()->template;
        }

        return '';
    }

    /**
     * The templates of the slots registered under the handle of the route's resource, skeletons
     * included: what `layout_slot()` and `layout_slot_deferred()` render on that page. Read from
     * the slot registry, which discovery fills from #[AsSlotResource] and the layout slots alike.
     * A slot under the global handle or a layout's is on no one page and is left to the fallback.
     *
     * @return list<string>
     */
    public static function slotTemplatesOf(mixed $resource): array
    {
        if (!is_string($resource) || !class_exists($resource)) {
            return [];
        }
        $templates = [];
        foreach ((new \ReflectionClass($resource))->getAttributes(AsResource::class) as $attribute) {
            $handle = (string) $attribute->newInstance()->handle;
            foreach ($handle === '' ? [] : LayoutSlotRegistry::getSlotsForHandle($handle) as $entries) {
                foreach ($entries as $entry) {
                    array_push($templates, $entry['template'], (string) ($entry['skeletonTemplate'] ?? ''));
                }
            }
            break;
        }

        return array_values(array_unique(array_filter($templates, static fn (string $template): bool => $template !== '')));
    }

    /**
     * The files of the route's handlers and of its resource: the PHP that may name a template the
     * page renders (`->withResultPreviewTemplate('@alias/…/x.html.twig')`). {@see PageUsers} reads
     * their string literals. A class that cannot be loaded or has no file gives none.
     *
     * @param array<string, mixed> $route as route discovery gives it
     * @return list<string> absolute paths
     */
    public static function sourcesOf(array $route): array
    {
        $classes = [$route['responseClass'] ?? $route['responseWith'] ?? null];
        foreach (is_array($route['handlers'] ?? null) ? $route['handlers'] : [] as $handler) {
            $classes[] = is_array($handler) ? $handler['class'] ?? null : $handler;
        }
        $files = [];
        foreach ($classes as $class) {
            $file = is_string($class) && $class !== '' && class_exists($class) ? (new \ReflectionClass($class))->getFileName() : false;
            if (is_string($file)) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /** @param array<string, mixed> $route as route discovery gives it */
    public static function isPage(array $route): bool
    {
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
        $access = $route['accessType'] ?? null;
        $access = $access instanceof PayloadAccessType ? $access->value : $access;
        $path = is_string($route['path'] ?? null) ? $route['path'] : '';
        if (!in_array('GET', (array) $methods, true) || $access !== 'public' || $path === '' || str_contains($path, '{') || preg_match(self::ACTIONS, rtrim($path, '/')) === 1) {
            return false;
        }
        if (self::isCollectionFeed($route['class'] ?? null)) {
            return false;
        }
        $produces = array_filter((array) ($route['produces'] ?? []), 'is_string');

        return $produces === [] || array_filter($produces, static fn (string $type): bool => str_contains($type, 'html')) !== [];
    }

    /**
     * A collection feed answers rows for a grid, not a page, though its route says
     * nothing it produces (a produces list would answer an SSE or browser request
     * 406). Read as HTML, a feed whose source refuses an anonymous visitor answered
     * 403 as a broken page (a consumer's team directory and activity feeds,
     * 2026-11-16). By name: semitexa-dev does not depend on crud. #[AsCrud], whose
     * route is the screen itself, stays a page. A feed says who may read it in its
     * attribute's name since crud#13 (#[AsProtectedFeed], #[AsPublicFeed]), and those
     * are feeds as much as #[AsCollectionFeed] is.
     */
    private const FEED_ATTRIBUTES = [
        'Semitexa\\Crud\\Attribute\\AsCollectionFeed',
        'Semitexa\\Crud\\Attribute\\AsProtectedFeed',
        'Semitexa\\Crud\\Attribute\\AsPublicFeed',
    ];

    private static function isCollectionFeed(mixed $class): bool
    {
        if (!is_string($class) || $class === '' || !class_exists($class)) {
            return false;
        }

        // Matched by name: the attribute class itself need not be loadable.
        $reflection = new \ReflectionClass($class);
        foreach (self::FEED_ATTRIBUTES as $attribute) {
            if ($reflection->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }
}
