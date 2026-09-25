<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * The input the hydrator received for the request this coroutine serves,
 * read off the live Swoole request: the body (JSON or form) first, then the
 * query keys the body did not set — PayloadHydrator's own order.
 *
 * Why not the hydrated payload: its state is whatever the payload chose to
 * keep, under names of its choosing (`setPerPage()` writing `$rawPerPage`),
 * so a snapshot of it cannot be fed back through the setters. The raw input
 * can — it is exactly what replay and the Explorer's recorded variations
 * need. Redaction is the caller's job, at ingestion, like every other value.
 */
final class LiveRequestInput
{
    /** @return array<string, mixed>|null null outside a live Swoole request */
    public static function current(): ?array
    {
        try {
            $pair = \Semitexa\Core\Server\SwooleBootstrap::getCurrentSwooleRequestResponse();
        } catch (\Throwable) {
            return null;
        }
        $request = is_array($pair) ? ($pair[0] ?? null) : null;
        if (!is_object($request)) {
            return null;
        }

        $query = is_array($request->get ?? null) ? $request->get : [];
        $headers = is_array($request->header ?? null) ? $request->header : [];

        return self::merge($query, self::body($request, (string) ($headers['content-type'] ?? '')));
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $body
     * @return array<string, mixed>
     */
    public static function merge(array $query, array $body): array
    {
        $input = [];
        foreach ($body as $key => $value) {
            $input[(string) $key] = $value;
        }
        foreach ($query as $key => $value) {
            // The trace marker rides the query string; it is not input.
            if ($key === '__trace' || array_key_exists((string) $key, $input)) {
                continue;
            }
            $input[(string) $key] = $value;
        }

        return $input;
    }

    /** @return array<array-key, mixed> */
    private static function body(object $request, string $contentType): array
    {
        if (str_contains(strtolower($contentType), 'json') && method_exists($request, 'rawContent')) {
            $decoded = json_decode((string) $request->rawContent(), true);

            return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
        }

        return is_array($request->post ?? null) ? $request->post : [];
    }
}
