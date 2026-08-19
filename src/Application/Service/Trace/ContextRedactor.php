<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * The one gate between real request data and the Observatory.
 *
 * Deep context (payload snapshots, query bindings) is what makes a dive
 * useful, and exactly what must never leak: traces and journal lines are
 * files on disk that outlive the request and get pasted into chats. So
 * redaction happens at INGESTION — before anything enters a buffer or a
 * journal line — and is not optional per call site: a consumer cannot opt
 * out of a rule it never sees applied.
 *
 * Key-based, not value-based: a value that LOOKS like a secret is guesswork,
 * a key named `password` is a statement of intent. Keys are normalized
 * (lowercased, `-`/`_` stripped) and matched on containment, so `Password`,
 * `api_key`, `X-Auth-Token` and `dbPassword` all hit. The needle list errs
 * toward false positives — losing a benign value from a trace is a shrug,
 * leaking one secret is an incident.
 */
final class ContextRedactor
{
    public const MASK = '[redacted]';

    /** Matched against normalized keys (lowercase, dashes/underscores removed). */
    private const NEEDLES = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'authorization',
        'apikey', 'credential', 'cookie', 'privatekey', 'sessionid', 'bearer',
    ];

    private const MAX_DEPTH = 4;
    private const MAX_ITEMS = 25;
    private const MAX_STRING = 200;

    /**
     * A redacted, size-bounded view of an object's public state — what the
     * hydrated-payload snapshot uses. Only initialized public properties:
     * that is the payload contract surface, and reaching further (private
     * state, getters with side effects) would turn observation into
     * interference.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(object $subject): array
    {
        $out = [];
        foreach ((new \ReflectionObject($subject))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || !$prop->isInitialized($subject)) {
                continue;
            }
            $out[$prop->getName()] = self::value($prop->getName(), $prop->getValue($subject), 1);
        }

        return $out;
    }

    /**
     * Deep-redact an array in place of trusting its producer.
     *
     * @param  array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        /** @var array<array-key, mixed> */
        return self::value('', $data, 1);
    }

    private static function value(string|int $key, mixed $value, int $depth): mixed
    {
        if (is_string($key) && self::isSensitiveKey($key)) {
            return self::MASK;
        }

        if (is_array($value)) {
            if ($depth >= self::MAX_DEPTH) {
                return '[array:' . count($value) . ']';
            }
            $out = [];
            $i = 0;
            foreach ($value as $k => $v) {
                if (++$i > self::MAX_ITEMS) {
                    $out['…'] = '[+' . (count($value) - self::MAX_ITEMS) . ' more]';
                    break;
                }
                $out[$k] = self::value($k, $v, $depth + 1);
            }

            return $out;
        }

        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => mb_strlen($value) > self::MAX_STRING
                ? mb_substr($value, 0, self::MAX_STRING) . '…'
                : $value,
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \Stringable => self::value($key, (string) $value, $depth),
            is_object($value) => $value::class,
            default => get_debug_type($value),
        };
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = str_replace(['-', '_'], '', strtolower($key));
        foreach (self::NEEDLES as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
