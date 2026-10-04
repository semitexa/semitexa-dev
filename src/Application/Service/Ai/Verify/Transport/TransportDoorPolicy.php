<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Transport;

/**
 * KISS and HUG are the whole browser↔server transport.
 *
 * The framework was designed with two doors: KISS (`GET /__semitexa_kiss`, the
 * one SSE stream per page) and HUG (`/__semitexa_hug`: POST for every event,
 * GET for the pull fallback). Every component, event, action and feed rides
 * them. Measured 2026-10-04 in git history: four more doors had crept in one
 * feature slice at a time (`/__semitexa_component_event`, `/__ui/event`,
 * `/__ui/dispatch`, `/__ui/form-doc`), each solving its problem locally, while
 * the operator repeated the rule to every agent. Nothing checked it; this does.
 *
 * A framework-internal route (`/__…`) must be one of the doors, a fixed system
 * page, or a development tool that never carries page traffic.
 */
final class TransportDoorPolicy
{
    /** path => allowed methods */
    public const DOORS = [
        '/__semitexa_kiss' => ['GET'],
        '/__semitexa_hug' => ['GET', 'POST'],
    ];

    /** Fixed system pages: not transport. */
    public const SYSTEM_PAGES = [
        '/__semitexa/error/404',
        '/__semitexa/error/500',
        '/__semitexa_locale',
    ];

    /** Development tools (gated to dev / an explicit flag); they never carry page traffic. */
    public const DEV_TOOL_PREFIXES = [
        '/__observatory',
        '/__explorer',
        '/__trace',
        '/__toolbar',
        '/__quality',
        '/__ui/workbench',
    ];

    /**
     * Doors still being folded into KISS/HUG, each with the task that removes
     * it. An entry here is debt with an owner, not permission: when the task
     * closes, the entry goes and the route must be gone too.
     */
    public const PENDING = [
        '/__ui/form-doc' => 'tk-kh-form-doc-over-kiss',
    ];

    /**
     * @param list<array{path?: string, methods?: list<string>, class?: string}> $routes
     * @return list<array{path: string, methods: list<string>, class: string, reason: string}>
     */
    public static function violations(array $routes): array
    {
        $found = [];
        foreach ($routes as $route) {
            $path = (string) ($route['path'] ?? '');
            if (!str_starts_with($path, '/__')) {
                continue;
            }
            $methods = array_values(array_map('strtoupper', $route['methods'] ?? ['GET']));
            $class = (string) ($route['class'] ?? '');

            if (isset(self::DOORS[$path])) {
                $extra = array_values(array_diff($methods, self::DOORS[$path], ['HEAD', 'OPTIONS']));
                if ($extra !== []) {
                    $found[] = ['path' => $path, 'methods' => $methods, 'class' => $class,
                        'reason' => sprintf('%s answers only %s; %s is a new verb on a door — extend the door\'s existing handler instead.', $path, implode('/', self::DOORS[$path]), implode('/', $extra))];
                }
                continue;
            }
            if (in_array($path, self::SYSTEM_PAGES, true) || isset(self::PENDING[$path])) {
                continue;
            }
            foreach (self::DEV_TOOL_PREFIXES as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix . '/') || str_starts_with($path, $prefix . '?')) {
                    continue 2;
                }
            }
            $found[] = ['path' => $path, 'methods' => $methods, 'class' => $class,
                'reason' => 'A new framework-internal door. Browser↔server UI traffic goes through KISS (GET /__semitexa_kiss, server→browser) and HUG (POST /__semitexa_hug, browser→server). Route the feature through them; a missing verb extends HUG.'];
        }
        // A pending entry whose route is gone is debt already paid: the entry
        // must go too, or the list would quietly re-admit a door added later.
        $present = array_flip(array_map(static fn (array $r): string => (string) ($r['path'] ?? ''), $routes));
        foreach (self::PENDING as $path => $task) {
            if (!isset($present[$path])) {
                $found[] = ['path' => $path, 'methods' => [], 'class' => '',
                    'reason' => sprintf('%s is listed as pending (%s) but no route declares it any more: remove it from TransportDoorPolicy::PENDING.', $path, $task)];
            }
        }

        return $found;
    }
}
