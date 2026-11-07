<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\ModuleRegistry;

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

    /** @return list<array{path: string, module: string}> */
    public static function of(?AttributeDiscovery $discovery, ?ModuleRegistry $modules): array
    {
        if ($discovery === null) {
            return [];
        }

        $routes = [];
        foreach ($discovery->getRoutes() as $route) {
            if (self::isPage($route)) {
                $routes[] = ['path' => (string) $route['path'], 'module' => $modules?->getModuleNameForClass((string) ($route['class'] ?? '')) ?? 'project'];
            }
        }

        return $routes;
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
        $produces = array_filter((array) ($route['produces'] ?? []), 'is_string');

        return $produces === [] || array_filter($produces, static fn (string $type): bool => str_contains($type, 'html')) !== [];
    }
}
