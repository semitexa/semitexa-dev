<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Auth\PayloadAccessType;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\ModuleRegistry;

/**
 * The routes a browser can open as they are, for {@see PageCheckTargets}:
 * GET, public, no parameter in the path. Each named by the module it belongs
 * to, as routes:list names it ("semitexa-demo" for a package, the module's own
 * name for one under src/modules).
 */
final class PageRoutes
{
    /** @return list<array{path: string, module: string}> */
    public static function of(?AttributeDiscovery $discovery, ?ModuleRegistry $modules): array
    {
        if ($discovery === null) {
            return [];
        }

        $routes = [];
        foreach ($discovery->getRoutes() as $route) {
            $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
            $access = $route['accessType'] ?? null;
            $access = $access instanceof PayloadAccessType ? $access->value : $access;
            $path = (string) ($route['path'] ?? '');
            if (!in_array('GET', (array) $methods, true) || $access !== 'public' || $path === '' || str_contains($path, '{')) {
                continue;
            }
            $class = (string) ($route['class'] ?? '');
            $routes[] = ['path' => $path, 'module' => $modules?->getModuleNameForClass($class) ?? 'project'];
        }

        return $routes;
    }
}
