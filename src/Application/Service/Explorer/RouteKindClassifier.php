<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

/**
 * Sorts one raw discovered route into a {@see RouteKind}.
 *
 * Reads the raw route array rather than DiscoveredRoute: `transport` arrives
 * as a TransportType enum as often as a string, and DiscoveredRoute::fromArray
 * keeps strings only — every enum-declared SSE route would read as plain HTTP.
 *
 * The declared render profile wins; legacy routes without one fall back to
 * `produces` and then to the response class. HtmlResponse lives in
 * semitexa/ssr, which dev does not require, so it is compared by name.
 */
final class RouteKindClassifier
{
    private const HTML_RESPONSE = 'Semitexa\\Ssr\\Application\\Service\\Http\\Response\\HtmlResponse';

    /** @param array<string, mixed> $route */
    public static function classify(array $route): RouteKind
    {
        $path = is_string($route['path'] ?? null) ? $route['path'] : '';
        if (str_starts_with($path, '/__')) {
            return RouteKind::Internal;
        }

        $produces = self::strings($route['produces'] ?? null);
        if (self::transport($route['transport'] ?? null) === 'sse' || in_array('text/event-stream', $produces, true)) {
            return RouteKind::Stream;
        }

        $profiles = self::profiles($route['renderProfile'] ?? null);
        if (in_array('graphql', $profiles, true)) {
            return RouteKind::GraphQl;
        }
        if ($profiles !== []) {
            return in_array('html', $profiles, true) ? RouteKind::Page : RouteKind::Api;
        }

        if (in_array('text/html', $produces, true)) {
            return RouteKind::Page;
        }
        $response = $route['responseClass'] ?? null;
        if (is_string($response) && $response !== '' && is_a($response, self::HTML_RESPONSE, true)) {
            return RouteKind::Page;
        }

        return RouteKind::Api;
    }

    public static function transport(mixed $transport): ?string
    {
        if ($transport instanceof \BackedEnum) {
            return (string) $transport->value;
        }

        return is_string($transport) && $transport !== '' ? $transport : null;
    }

    /** @return list<string> */
    public static function profiles(mixed $profile): array
    {
        $profiles = is_array($profile) ? $profile : [$profile];
        $values = [];
        foreach ($profiles as $item) {
            if ($item instanceof \BackedEnum) {
                $values[] = (string) $item->value;
            } elseif (is_string($item) && $item !== '') {
                $values[] = $item;
            }
        }

        return $values;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }
}
