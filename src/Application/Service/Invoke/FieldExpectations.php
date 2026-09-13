<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Invoke;

use Semitexa\Dev\Application\Service\Trace\ContextRedactor;

/**
 * What the caller said the resource should contain, checked against what it
 * does.
 *
 * `ai:invoke` answers with the whole resource, which is the right default for a
 * developer reading it — and the wrong one for anything that wants a yes or a
 * no. An agent asserting "the total came back as 3" had to serialise the
 * envelope, decode it and index into it, and every one of those steps is a
 * place to get it wrong quietly.
 *
 * ## Comparison is textual, and says so
 *
 * `--expect-field=total=3` compares the rendered value, not a typed one: `3`
 * matches int 3 and string "3" alike, `true` matches the boolean, `null`
 * matches null. A shell flag carries no types, so pretending otherwise would
 * mean inventing a syntax for them; instead the rule is one sentence long and
 * the report shows exactly what was compared. A value that is not a scalar
 * never matches, and reports its type rather than its contents.
 *
 * ## Secrets
 *
 * The comparison uses the REAL value, or asserting anything under a
 * secret-looking key would be comparing against a mask and always failing. The
 * REPORT shows the redacted form, because this envelope is printed, piped and
 * pasted like any other — with `actual_redacted` set, so nobody reads the mask
 * as the thing that was compared.
 */
final readonly class FieldExpectations
{
    /** @param list<array{path: string, expected: string}> $expectations */
    private function __construct(public array $expectations)
    {
    }

    /**
     * @param list<string> $raw `path=value`, dot-separated path
     * @throws \InvalidArgumentException when an entry has no `=`
     */
    public static function fromOptions(array $raw): self
    {
        $parsed = [];

        foreach ($raw as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $at = strpos($entry, '=');
            if ($at === false || $at === 0) {
                throw new \InvalidArgumentException(
                    sprintf('--expect-field needs path=value, got "%s".', $entry),
                );
            }

            $parsed[] = [
                'path' => trim(substr($entry, 0, $at)),
                'expected' => substr($entry, $at + 1),
            ];
        }

        return new self($parsed);
    }

    public function isEmpty(): bool
    {
        return $this->expectations === [];
    }

    /**
     * @param array<string, mixed>|null $resource the RAW resource, before redaction
     * @return array{checked: int, failed: int, results: list<array<string, mixed>>}
     */
    public function check(?array $resource): array
    {
        $results = [];
        $failed = 0;

        foreach ($this->expectations as $expectation) {
            $path = $expectation['path'];
            $expected = $expectation['expected'];

            [$found, $actual] = self::lookup($resource, $path);
            $rendered = $found ? self::render($actual) : null;
            $ok = $found && $rendered === $expected;

            if (!$ok) {
                $failed++;
            }

            $entry = [
                'path' => $path,
                'expected' => $expected,
                'ok' => $ok,
            ];

            if (!$found) {
                $entry['actual'] = null;
                $entry['reason'] = 'no such path in the resource';
            } else {
                // The redactor decides by KEY name, so it is asked about the
                // leaf the value actually sits under.
                $leaf = self::leafKey($path);
                $masked = self::render(ContextRedactor::redact([$leaf => $actual])[$leaf] ?? $actual);
                $entry['actual'] = $masked;
                if ($masked !== $rendered) {
                    // Said out loud: the comparison used the real value, this
                    // line is only what may be shown.
                    $entry['actual_redacted'] = true;
                }
            }

            $results[] = $entry;
        }

        return ['checked' => count($results), 'failed' => $failed, 'results' => $results];
    }

    private static function leafKey(string $path): string
    {
        $segments = explode('.', $path);

        return (string) end($segments);
    }

    /**
     * @param array<string, mixed>|null $resource
     * @return array{0: bool, 1: mixed} [found, value]
     */
    private static function lookup(?array $resource, string $path): array
    {
        $cursor = $resource;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return [false, null];
            }
            $cursor = $cursor[$segment];
        }

        return [true, $cursor];
    }

    /**
     * One value, as the flag would have to spell it.
     *
     * Anything that is not a scalar is rendered as its type in angle brackets,
     * which no `--expect-field` value can accidentally equal — a caller who
     * writes `items=<array>` gets a mismatch and a report saying so, rather
     * than an accidental pass.
     */
    private static function render(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            $value === true => 'true',
            $value === false => 'false',
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            default => '<' . get_debug_type($value) . '>',
        };
    }
}
