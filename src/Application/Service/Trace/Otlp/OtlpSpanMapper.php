<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace\Otlp;

/**
 * Turn a recorded trace into OTLP spans.
 *
 * The buffer records EVENTS — `begin`, `end`, `mark` — each carrying a name, a
 * coroutine id and a millisecond offset from the root. OTLP wants SPANS: an id,
 * a parent, a start and an end in nanoseconds. This pairs the one into the other
 * and does nothing else, so the conversion can be tested without a collector,
 * a socket, or a running server.
 *
 * Pairing is per coroutine and by name, on a stack. Two spans with the same name
 * can nest inside one coroutine (a re-dispatch through the pipeline does exactly
 * that), so the closing event matches the innermost open span of that name — the
 * only rule under which nesting survives.
 *
 * What this deliberately does NOT do:
 *
 *  - invent an end for a span that never closed. A worker killed mid-request
 *    leaves an open span, and reporting it as having ended at the trace's last
 *    known moment would turn "we lost the request" into "the request finished".
 *    Those are dropped and counted.
 *  - promote a `mark` to a span. A mark is a point in time; OTLP has span events
 *    for that, and they attach to the span that was open when the mark landed.
 */
final class OtlpSpanMapper
{
    /**
     * @param array{rootCid?: int|null, totalMs?: float|int, events?: list<array<string, mixed>>} $trace
     * @param int $startedAtNanos wall-clock start of the root span
     * @return array{spans: list<array<string, mixed>>, unclosed: int}
     */
    public static function map(array $trace, string $traceId, int $startedAtNanos): array
    {
        /** @var array<int, list<array{name: string, startMs: float, spanId: string, parentId: string, attributes: array<string, mixed>, events: list<array<string, mixed>>}>> $open */
        $open = [];
        /** @var array<int, string> $currentParent */
        $currentParent = [];
        $spans = [];
        $unclosed = 0;

        foreach ($trace['events'] ?? [] as $event) {
            $type = is_string($event['type'] ?? null) ? $event['type'] : '';
            $name = is_string($event['name'] ?? null) ? $event['name'] : '';
            $cid = is_int($event['cid'] ?? null) ? $event['cid'] : 0;
            $atMs = is_numeric($event['atMs'] ?? null) ? (float) $event['atMs'] : 0.0;
            $context = is_array($event['context'] ?? null) ? $event['context'] : [];

            if ($type === 'begin') {
                // A coroutine's first span hangs off its parent coroutine's
                // innermost open span when there is one, so the waterfall keeps
                // its shape across a spawn instead of flattening into roots.
                $pcid = is_int($event['pcid'] ?? null) ? $event['pcid'] : null;
                $parentId = ($open[$cid] ?? []) !== []
                    ? end($open[$cid])['spanId']
                    : ($pcid !== null ? ($currentParent[$pcid] ?? '') : '');

                $spanId = self::id(8);
                $open[$cid][] = [
                    'name' => $name,
                    'startMs' => $atMs,
                    'spanId' => $spanId,
                    'parentId' => $parentId,
                    'attributes' => $context,
                    'events' => [],
                ];
                $currentParent[$cid] = $spanId;
                continue;
            }

            if ($type === 'mark') {
                if (($open[$cid] ?? []) === []) {
                    continue;
                }

                $last = array_key_last($open[$cid]);
                $open[$cid][$last]['events'][] = [
                    'name' => $name,
                    'timeUnixNano' => (string) ($startedAtNanos + (int) round($atMs * 1_000_000)),
                    'attributes' => self::attributes($context),
                ];
                continue;
            }

            if ($type !== 'end' || ($open[$cid] ?? []) === []) {
                continue;
            }

            $index = null;
            foreach (array_reverse(array_keys($open[$cid])) as $candidate) {
                if ($open[$cid][$candidate]['name'] === $name) {
                    $index = $candidate;
                    break;
                }
            }

            if ($index === null) {
                continue;
            }

            $span = $open[$cid][$index];
            unset($open[$cid][$index]);
            $open[$cid] = array_values($open[$cid]);
            $currentParent[$cid] = $open[$cid] !== [] ? end($open[$cid])['spanId'] : '';

            $spans[] = [
                'traceId' => $traceId,
                'spanId' => $span['spanId'],
                'parentSpanId' => $span['parentId'],
                'name' => $span['name'],
                'kind' => 1,
                'startTimeUnixNano' => (string) ($startedAtNanos + (int) round($span['startMs'] * 1_000_000)),
                'endTimeUnixNano' => (string) ($startedAtNanos + (int) round($atMs * 1_000_000)),
                'attributes' => self::attributes(array_merge($span['attributes'], $context)),
                'events' => $span['events'],
            ];
        }

        foreach ($open as $stack) {
            $unclosed += count($stack);
        }

        return ['spans' => $spans, 'unclosed' => $unclosed];
    }

    /**
     * OTLP attributes are a typed list, not a map, and nested values have no
     * representation — they are JSON-encoded rather than dropped, because the
     * context a developer added is the reason they opened the trace.
     *
     * @param array<string, mixed> $context
     * @return list<array<string, mixed>>
     */
    private static function attributes(array $context): array
    {
        $out = [];

        foreach ($context as $key => $value) {
            $out[] = ['key' => (string) $key, 'value' => match (true) {
                is_bool($value) => ['boolValue' => $value],
                is_int($value) => ['intValue' => (string) $value],
                is_float($value) => ['doubleValue' => $value],
                is_string($value) => ['stringValue' => $value],
                $value === null => ['stringValue' => ''],
                default => ['stringValue' => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            }];
        }

        return $out;
    }

    public static function id(int $bytes): string
    {
        return bin2hex(random_bytes($bytes));
    }
}
