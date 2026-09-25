<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * A route pattern (`/items/{id}`) read against a concrete path (`/items/7`):
 * the placeholder values, and the path with the secret ones masked.
 *
 * Placeholders are NAMED captures. A requirement may carry groups of its own
 * — `(\d+)` is a valid requirement — and counting captures positionally then
 * hands one placeholder's value to the next (`/items/{id}/tags/{slug}` with
 * `id: (\d+)` gave `slug` the id). Only captured values are URL-decoded, so an
 * encoded slash inside a segment cannot move a segment boundary.
 */
final class RoutePath
{
    /**
     * @param  array<array-key, mixed> $requirements
     * @return array<string, string>
     */
    public static function params(string $pattern, array $requirements, string $path): array
    {
        $names = [];
        $regex = preg_replace_callback(
            '/\\\\\{([^}:]+)(?:\\\\:[^}]*)?\\\\\}/',
            static function (array $m) use ($requirements, &$names): string {
                // The callback sees the preg_quote()d pattern: `{user-id}` arrives
                // as `user\-id`. The key must be the name the route declares.
                $name = stripslashes($m[1]);
                $group = 'p' . count($names);
                $names[$group] = $name;
                $requirement = $requirements[$name] ?? null;

                return '(?P<' . $group . '>' . (is_string($requirement) && $requirement !== '' ? $requirement : '[^/]+') . ')';
            },
            preg_quote($pattern, '#'),
        );
        if ($names === [] || !is_string($regex) || @preg_match('#^' . $regex . '$#', $path, $matches) !== 1) {
            return [];
        }

        $params = [];
        foreach ($names as $group => $name) {
            if (isset($matches[$group]) && is_string($matches[$group])) {
                $params[$name] = rawurldecode($matches[$group]);
            }
        }

        return $params;
    }

    /**
     * The concrete path with every placeholder whose NAME marks a secret
     * (`/reset/{token}`) replaced by the mask — the rule ContextRedactor applies
     * to input fields, applied to the path. A path that does not match its
     * pattern is not trusted to be secret-free and comes back null.
     */
    public static function redact(string $pattern, string $path): ?string
    {
        if (!str_contains($pattern, '{')) {
            return $path;
        }
        $params = self::params($pattern, [], $path);
        if ($params === []) {
            return null;
        }

        return (string) preg_replace_callback(
            '/\{([^}:]+)(?::[^}]*)?\}/',
            static function (array $m) use ($params): string {
                $value = $params[$m[1]] ?? '';

                return ContextRedactor::isSensitiveKey($m[1]) ? ContextRedactor::MASK : rawurlencode($value);
            },
            $pattern,
        );
    }
}
