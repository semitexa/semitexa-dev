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
     * A redacted, size-bounded view of an object's input state — what the
     * hydrated-payload snapshot uses: initialized public properties, and the
     * non-public properties the hydrator writes through a public `set{Name}()`.
     *
     * The setter pair is the payload contract surface in Semitexa — the
     * canonical payload is a private property with a setter, and a snapshot of
     * public properties alone came back EMPTY for it, so a replay re-ran the
     * handler with no input at all. Values are read straight off the
     * property: no getter runs, so observing still cannot interfere. State
     * without a setter (caches, derived fields) stays out.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(object $subject): array
    {
        $out = [];
        $class = new \ReflectionObject($subject);
        foreach ($class->getProperties() as $prop) {
            if ($prop->isStatic() || !$prop->isInitialized($subject)) {
                continue;
            }
            if (!$prop->isPublic() && !self::hasInputSetter($class, $prop->getName())) {
                continue;
            }
            $out[$prop->getName()] = self::value($prop->getName(), $prop->getValue($subject), 1);
        }

        return $out;
    }

    private static function hasInputSetter(\ReflectionClass $class, string $property): bool
    {
        $setter = 'set' . ucfirst($property);
        if (!$class->hasMethod($setter)) {
            return false;
        }
        $method = $class->getMethod($setter);

        return $method->isPublic() && !$method->isStatic() && $method->getNumberOfRequiredParameters() === 1;
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

    /**
     * Public because it is the one definition of "this name holds a secret",
     * and a second copy of the needle list somewhere else is a copy that drifts
     * out of step with this one silently. `--expect-field` asks it about a path
     * segment when there is no value to inspect.
     */
    public static function isSensitiveKey(string $key): bool
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
