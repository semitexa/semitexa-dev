<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Lifecycle\CurrentRequestStore;

/**
 * What the tracer keeps about a hydrated payload, every value redacted at
 * ingestion:
 *
 * - `snapshot` — the payload object's input state ({@see ContextRedactor::snapshot()});
 * - `input` — the body + query the hydrator received ({@see LiveRequestInput}),
 *   the one of the two a replay can feed back through the setters;
 * - `request_path` — the concrete routed path. The root span records only the
 *   route PATTERN (`/items/{id}`), and `{id}`'s value lives in the path. Masked
 *   by placeholder name like any input (`/reset/{token}` keeps no token), and
 *   not recorded at all when there is no pattern to check it against.
 */
final class HydrationCapture
{
    /** @return array{snapshot?: array<string, mixed>, input?: array<array-key, mixed>, request_path?: string} */
    public static function of(object $payload, TraceBuffer $buffer): array
    {
        $captured = ['snapshot' => ContextRedactor::snapshot($payload)];

        $raw = LiveRequestInput::current();
        if ($raw !== null) {
            $captured['input'] = ContextRedactor::redact($raw);
        }

        $concrete = CurrentRequestStore::get()?->getPath();
        $pattern = self::rootPattern($buffer);
        $path = is_string($concrete) && $pattern !== null ? RoutePath::redact($pattern, $concrete) : null;
        if (is_string($path) && $path !== '') {
            $captured['request_path'] = $path;
        }

        return $captured;
    }

    /** The route pattern the root request span recorded, if this buffer has one. */
    private static function rootPattern(TraceBuffer $buffer): ?string
    {
        foreach ($buffer->events() as $event) {
            if (($event['type'] ?? null) === 'begin' && ($event['name'] ?? null) === 'request') {
                $path = $event['context']['path'] ?? null;

                return is_string($path) && $path !== '' ? $path : null;
            }
        }

        return null;
    }
}
