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
        // The whole resource through the redactor ONCE, and display values read
        // out of THAT. Masking by the leaf key alone asked the wrong question:
        // `credentials.value` is a `value`, which nothing treats as secret,
        // while the redactor looking at the real structure masks it. Raised in
        // review of dev#84.
        $shown = $resource === null ? null : ContextRedactor::redact($resource);

        $results = [];
        $failed = 0;

        foreach ($this->expectations as $expectation) {
            $path = $expectation['path'];
            $expected = $expectation['expected'];

            [$found, $actual] = self::lookup($resource, $path);
            $rendered = $found ? self::render($actual) : null;

            // Scalar-ness is part of the verdict, not a property of the label.
            // `<array>` was supposed to be unspellable; a caller can spell it,
            // and then an assertion against a list passed. Raised in review of
            // dev#84.
            $comparable = $found && self::isComparable($actual);
            $ok = $comparable && $rendered === $expected;

            if (!$ok) {
                $failed++;
            }

            $maskedRendered = $found ? self::maskedDisplay($shown, $path) : null;
            $isSecret = $found && $maskedRendered !== $rendered;

            $entry = [
                'path' => $path,
                // The caller's own text is masked whenever the path is, or
                // `--expect-field=password=hunter2` printed the secret verbatim
                // in an envelope built to be pasted around — back in through
                // the door the mask was guarding.
                'expected' => $isSecret ? $maskedRendered : $expected,
                'ok' => $ok,
            ];
            if ($isSecret) {
                $entry['expected_redacted'] = true;
            }

            if (!$found) {
                $entry['actual'] = null;
                $entry['reason'] = 'no such path in the resource';
            } elseif (!$comparable) {
                $entry['actual'] = $maskedRendered;
                $entry['reason'] = 'the value is ' . get_debug_type($actual) . ', which no --expect-field value can equal';
            } else {
                $entry['actual'] = $maskedRendered;
                if ($isSecret) {
                    // Said out loud: the comparison used the real value, this
                    // line is only what may be shown.
                    $entry['actual_redacted'] = true;
                }
            }

            $results[] = $entry;
        }

        return ['checked' => count($results), 'failed' => $failed, 'results' => $results];
    }

    /**
     * What may be SHOWN for this path.
     *
     * Read out of the redacted copy, so the redactor's own rules decide. It
     * masks a secret-looking CONTAINER whole rather than descending into it —
     * so `credentials.password` has no path left to find, and neither does
     * `credentials.user` beside it. That is the redactor's decision and it is
     * honoured rather than worked around: the nearest surviving ancestor IS the
     * mask, and showing it is both truthful and safe.
     *
     * @param array<string, mixed>|null $shown
     */
    private static function maskedDisplay(?array $shown, string $path): ?string
    {
        [$found, $value] = self::lookup($shown, $path);
        if ($found) {
            return self::render($value);
        }

        $segments = explode('.', $path);
        while (count($segments) > 1) {
            array_pop($segments);
            [$found, $value] = self::lookup($shown, implode('.', $segments));
            if ($found && self::isComparable($value)) {
                return self::render($value);
            }
        }

        return null;
    }

    /**
     * Can this value be compared at all?
     *
     * Only scalars and null. Everything else renders as its type, and that
     * rendering is a description rather than a value — treating it as one made
     * `--expect-field=items=<array>` pass against any array.
     */
    private static function isComparable(mixed $value): bool
    {
        return $value === null || is_scalar($value);
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
