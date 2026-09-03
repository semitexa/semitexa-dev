<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

/**
 * The code a recorded span points at: a class, and the method it entered when
 * the span said so.
 *
 * One rule, shared by the HTML viewer and `ai:observe show --source`, so the
 * two can never disagree about which step is "clickable". Matched on shape
 * rather than against a fixed list of context keys, so a span added later — a
 * new gate, a new pipeline phase — is resolvable the day it is recorded
 * instead of the day someone remembers to extend a list here.
 */
final readonly class SpanTarget
{
    /**
     * Keys checked first, in order. A span can name several classes (a listener
     * span names the event too); the one under these keys is what RAN.
     */
    private const PREFERRED = ['handler', 'payload', 'resource', 'gate', 'listener'];

    /** Not method names, whatever their shape: the root request span records the HTTP verb under `method`. */
    private const HTTP_VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'];

    public function __construct(
        public string $class,
        public ?string $method,
    ) {
    }

    /**
     * @param array<mixed, mixed> $context a span's recorded context
     */
    public static function of(array $context): ?self
    {
        $class = null;

        foreach (self::PREFERRED as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && self::looksLikeClass($value)) {
                $class = $value;
                break;
            }
        }

        if ($class === null) {
            foreach ($context as $value) {
                if (is_string($value) && self::looksLikeClass($value)) {
                    $class = $value;
                    break;
                }
            }
        }

        if ($class === null) {
            return null;
        }

        $method = $context['method'] ?? null;
        if (!is_string($method)
            || preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $method) !== 1
            || in_array($method, self::HTTP_VERBS, true)
        ) {
            $method = null;
        }

        return new self($class, $method);
    }

    /** `Class::method` or `Class`, for keys and labels. */
    public function key(): string
    {
        return $this->method === null ? $this->class : $this->class . '::' . $this->method;
    }

    public static function looksLikeClass(string $value): bool
    {
        return str_contains($value, '\\')
            && preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)+$/', $value) === 1;
    }
}
