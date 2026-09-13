<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

/**
 * Which named method a line of PHP sits in.
 *
 * {@see AcceptedViolations} accepts a violation at a SITE, and a diagnostic
 * names only a file, a rule and a line. The file and rule were enough to
 * consume the allowance, so removing the blessed call and writing a different
 * one elsewhere in the same class kept the gate green with somebody else's
 * reason attached to it. Raised in review of dev#83.
 *
 * The line is not the fingerprint: it moves whenever anything above it does,
 * and a registry that goes red on an unrelated edit teaches people to edit the
 * registry. The message is not one either — `semitexa.staticContainerAccess`
 * names the class and not the method, so two sites in one class read
 * identically. The enclosing method is stable under edits and distinguishes
 * them, so that is what is recorded.
 *
 * Closures and arrow functions are deliberately transparent: a violation inside
 * a callback belongs to the method that wrote the callback, which is the thing
 * a reader recognises and the thing the reason was written about.
 */
final class EnclosingSymbol
{
    /** @var array<string, list<array{name: string, from: int, to: int}>> */
    private static array $cache = [];

    /** The innermost NAMED function containing $line, or null if the line is outside all of them. */
    public static function at(string $file, int $line): ?string
    {
        $best = null;
        foreach (self::rangesIn($file) as $range) {
            if ($line < $range['from'] || $line > $range['to']) {
                continue;
            }
            if ($best === null || $range['from'] > $best['from']) {
                $best = $range;
            }
        }

        return $best['name'] ?? null;
    }

    /** @return list<array{name: string, from: int, to: int}> */
    private static function rangesIn(string $file): array
    {
        if (isset(self::$cache[$file])) {
            return self::$cache[$file];
        }

        $source = @file_get_contents($file);
        if ($source === false) {
            return self::$cache[$file] = [];
        }

        $ranges = [];
        $open = [];
        $pending = null; // false while awaiting the name after `function`, then the name
        $depth = 0;
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                // `"{$x}"` and `"${x}"` OPEN with a token and CLOSE with a
                // plain `}` character, so a file with any interpolation in it
                // unbalanced the depth and every method after that point was
                // attributed to nothing at all.
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                    continue;
                }
                if ($token[0] === T_FUNCTION) {
                    $pending = false;
                } elseif ($pending === false && $token[0] === T_STRING) {
                    $pending = $token[1];
                } elseif ($pending === false && $token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT) {
                    $pending = null; // a closure: `function (` or `function use`
                }
                continue;
            }

            // `function (` — a closure, whose `(` arrives as a plain character
            // while the name is still awaited. Without this the `void` of
            // `function (): void` was taken for the name. `&` is the one
            // character that may precede a name.
            if ($pending === false && $token !== '&') {
                $pending = null;
            }

            if ($token === ';') {
                $pending = null; // an abstract or interface method has no body to open
                continue;
            }

            if ($token === '{') {
                $depth++;
                if (is_string($pending)) {
                    $open[] = ['name' => $pending, 'depth' => $depth, 'from' => $line];
                    $pending = null;
                }
                continue;
            }

            if ($token === '}') {
                $last = $open === [] ? null : $open[count($open) - 1];
                if ($last !== null && $last['depth'] === $depth) {
                    array_pop($open);
                    $ranges[] = ['name' => $last['name'], 'from' => $last['from'], 'to' => $line];
                }
                $depth--;
            }
        }

        return self::$cache[$file] = $ranges;
    }

    /** @internal Test seam. */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
