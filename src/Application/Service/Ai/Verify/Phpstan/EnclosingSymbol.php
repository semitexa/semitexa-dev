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
 *
 * ## A line outside every method belongs to its CLASS
 *
 * Some rules report on the class itself — `semitexa.domainModelEncapsulation`
 * names the mapper and reports at the declaration. Such a line has no enclosing
 * method, this returned null, null never equalled a site string, and the entry
 * that should have accepted it silently did nothing: the only options left were
 * to obey a rule that made the code worse or to endure the violation forever.
 * So a line outside every method is attributed to the innermost class,
 * interface, trait or enum containing it, and `site` may name one.
 *
 * A DECLARATION BEGINS AT ITS ATTRIBUTES, not at its brace. PHPStan reports a
 * class error at the node's start line, and a node with attributes starts at
 * the first `#[` — line 14 of WebhookInboxMapper.php is `#[AsMapper(...)]`,
 * one line ABOVE `final class WebhookInboxMapper`. A range that began at the
 * keyword would have missed every attributed class in the codebase, which is
 * most of them, while looking like it worked on a test fixture without
 * attributes.
 */
final class EnclosingSymbol
{
    /**
     * Tokens that may sit between `function` and the name, and therefore must
     * not be taken for evidence that there is no name.
     *
     * The ampersand of `public function &items(): array` is the one that is not
     * obvious. PHP 8.1 stopped emitting it as a plain `&` character and gives
     * `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` instead, so the by-reference
     * method fell into the "no name of its own" branch and got no range at all
     * — and a diagnostic inside it then resolved to the enclosing CLASS, where
     * it could match a class-level accepted site and consume an allowance
     * written for something else.
     */
    private const BEFORE_A_NAME = [
        T_WHITESPACE,
        T_COMMENT,
        T_DOC_COMMENT,
        T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG,
        T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG,
    ];

    /** @var array<string, list<array{name: string, kind: 'function'|'class', from: int, to: int}>> */
    private static array $cache = [];

    /**
     * The innermost NAMED function containing $line; failing that, the
     * innermost class-like containing it; null when the line is inside neither.
     *
     * A method always wins over the class that holds it — it is the narrower
     * statement, and it is what the existing entries name.
     */
    public static function at(string $file, int $line): ?string
    {
        $bestFunction = null;
        $bestClass = null;

        foreach (self::rangesIn($file) as $range) {
            if ($line < $range['from'] || $line > $range['to']) {
                continue;
            }

            if ($range['kind'] === 'function') {
                if ($bestFunction === null || $range['from'] > $bestFunction['from']) {
                    $bestFunction = $range;
                }
                continue;
            }

            if ($bestClass === null || $range['from'] > $bestClass['from']) {
                $bestClass = $range;
            }
        }

        return $bestFunction['name'] ?? $bestClass['name'] ?? null;
    }

    /** @return list<array{name: string, kind: 'function'|'class', from: int, to: int}> */
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
        // kind of the declaration being read, its name once seen (false while
        // awaiting it), and the line it STARTS on — its first attribute when it
        // has one, which is the line PHPStan reports a class error at.
        $pending = null;
        $attributeLine = null;
        $previous = null;
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
                if ($token[0] === T_ATTRIBUTE) {
                    // The EARLIEST of a run of attribute groups, because that is
                    // where the declaration they decorate begins.
                    $attributeLine ??= $line;
                    continue;
                }
                if ($token[0] === T_FUNCTION) {
                    $pending = ['kind' => 'function', 'name' => false, 'from' => $attributeLine ?? $line];
                } elseif (self::opensAClassLike($token[0], $previous)) {
                    $pending = ['kind' => 'class', 'name' => false, 'from' => $attributeLine ?? $line];
                } elseif ($pending !== null && $pending['name'] === false && $token[0] === T_STRING) {
                    $pending['name'] = $token[1];
                } elseif ($pending !== null && $pending['name'] === false
                    && !in_array($token[0], self::BEFORE_A_NAME, true)) {
                    // `function (`, `function use`, `new class extends` — a
                    // declaration with no name of its own is transparent.
                    $pending = null;
                }

                if ($token[0] !== T_WHITESPACE && $token[0] !== T_COMMENT && $token[0] !== T_DOC_COMMENT) {
                    $previous = $token[0];
                }
                continue;
            }

            // `function (` — a closure, whose `(` arrives as a plain character
            // while the name is still awaited. Without this the `void` of
            // `function (): void` was taken for the name. `&` is the one
            // character that may precede a name.
            if ($pending !== null && $pending['name'] === false && $token !== '&') {
                $pending = null;
            }

            $previous = null;

            if ($token === ';') {
                // An abstract or interface method has no body to open; a
                // `use ...;` or a property ends the run of attributes above it.
                $pending = null;
                $attributeLine = null;
                continue;
            }

            if ($token === '{') {
                $depth++;
                if ($pending !== null && is_string($pending['name'])) {
                    $open[] = [
                        'name' => $pending['name'],
                        'kind' => $pending['kind'],
                        'depth' => $depth,
                        'from' => $pending['from'],
                    ];
                }
                $pending = null;
                $attributeLine = null;
                continue;
            }

            if ($token === '}') {
                $last = $open === [] ? null : $open[count($open) - 1];
                if ($last !== null && $last['depth'] === $depth) {
                    array_pop($open);
                    $ranges[] = [
                        'name' => $last['name'],
                        'kind' => $last['kind'],
                        'from' => $last['from'],
                        'to' => $line,
                    ];
                }
                $depth--;
                $attributeLine = null;
            }
        }

        return self::$cache[$file] = $ranges;
    }

    /**
     * Is this token a class-like declaration, rather than one of the two places
     * the same keyword means something else?
     *
     * `Foo::class` is a constant and appears in every attribute argument in the
     * codebase; `new class` is anonymous and, like a closure, belongs to
     * whatever wrote it. Neither opens a named range.
     */
    private static function opensAClassLike(int $token, ?int $previous): bool
    {
        if ($token !== T_CLASS && $token !== T_INTERFACE && $token !== T_TRAIT && $token !== T_ENUM) {
            return false;
        }

        return $previous !== T_DOUBLE_COLON && $previous !== T_NEW;
    }

    /** @internal Test seam. */
    public static function reset(): void
    {
        self::$cache = [];
    }
}
