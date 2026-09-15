<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Mechanism;

/**
 * Where a PHP file declares the prompt-catalog mechanism, and what its names mean.
 *
 * Split out of {@see HeredocPromptDetector} once review had grown it past the
 * structural budget: the detector answers "is this heredoc a model prompt", and
 * everything here answers a different question — "what does this name refer to
 * in this file". That second question turned out to carry most of the weight,
 * because a short name is not an identity:
 *
 *   - `use Vendor\Ui\AsPrompt as CatalogPrompt;` is a DIFFERENT attribute;
 *   - an unimported `#[AsPrompt]` resolves against the file's own namespace;
 *   - PHP matches all of these case-insensitively;
 *   - one `use` can introduce several names, grouped or comma-separated.
 *
 * Getting any of those wrong exempts a file that should be reported, which is
 * the expensive direction: the rule goes quiet exactly where it should speak.
 *
 * Everything is read from tokens. An `#[AsPrompt]` written in a docblock to
 * document a migration is prose, and exempting a service for it hid the real
 * findings in that service.
 */
final class CatalogPromptDeclarations
{
    /** The symbols that mark a class as the mechanism, fully qualified. */
    private const ATTRIBUTE_FQCN = 'Semitexa\\Prompt\\Attribute\\AsPrompt';
    private const INTERFACE_FQCN = 'Semitexa\\Prompt\\Domain\\Contract\\PromptDefinitionInterface';

    /**
     * Token ranges of the classes in this file that DECLARE a catalog prompt.
     * Such a class is the mechanism, not a duplicate of it — including one still
     * on the legacy `PromptDefinitionInterface::system()` path, where a heredoc
     * body is the supported migration shape. Firing there would point at the
     * correct solution and call it the mistake.
     *
     * Scoped to the class, not the file: one file may hold several classes, and
     * a whole-file exemption let a catalog declaration hide hard-coded prompts
     * in a neighbouring class that has nothing to do with the mechanism.
     *
     * Read from tokens: an `#[AsPrompt]` written in a docblock to document a
     * migration is prose, and exempting a whole service for it silently hid the
     * real findings that service had. `T_ATTRIBUTE` is emitted only where PHP
     * attaches an attribute. Qualified names, aliased imports and declarations
     * wrapped across lines all fall out of this for free.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<array{0: int, 1: int}>
     */
    public static function classRanges(array $tokens): array
    {
        // Namespace and imports are tracked AS the walk proceeds, not computed
        // once for the file. PHP scopes imports to their namespace block, and a
        // file may hold several: computing one map merged every block's imports
        // and let a later `use Vendor\AsPrompt` decide how an earlier block's
        // catalog class resolved — in either direction, depending on order.
        $namespace = '';
        $aliases = [];
        $count = \count($tokens);

        $ranges = [];
        $markedFrom = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!\is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = self::namespaceAt($tokens, $i);
                $aliases = [];
                continue;
            }

            if ($token[0] === T_USE) {
                // Top-level only. Inside a class-like body `use X;` composes a
                // TRAIT and imports nothing, so reading it as an import let
                // `trait T { use \Vendor\AsPrompt; }` shadow the real attribute
                // import and un-exempt a genuine catalog class. The class-like
                // branches below skip their bodies, so reaching here means top
                // level — except for the one shape they did not skip.
                $aliases += self::importsAt($tokens, $i);
                continue;
            }

            // An attribute sits BEFORE its class, so remember where it started;
            // the range opens there and the class body closes it.
            if ($token[0] === T_ATTRIBUTE) {
                // The attribute must be the one attached to a CLASS. #[AsPrompt]
                // on an enum case, a constant, a property or a parameter is not
                // a catalog declaration, and a marker left standing from one of
                // those exempted whatever class happened to come next.
                if (!self::marksAClass($tokens, $i)) {
                    continue;
                }
                foreach (self::attributeNames($tokens, $i) as $name) {
                    if (self::resolves($name, self::ATTRIBUTE_FQCN, $aliases, $namespace)) {
                        $markedFrom ??= $i;
                    }
                }
                continue;
            }

            if ($token[0] === T_IMPLEMENTS) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $candidate = $tokens[$j];
                    if (!\is_array($candidate)) {
                        if (self::text($candidate) === '{') {
                            break;
                        }
                        continue;
                    }
                    if (\in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    if (self::resolves($candidate[1], self::INTERFACE_FQCN, $aliases, $namespace)) {
                        $markedFrom ??= $i;
                        break;
                    }
                }
                continue;
            }

            // Traits, interfaces and enums have bodies too, and only the class
            // branch used to skip past one — so a `use` inside a trait was still
            // read as an import.
            if (\in_array($token[0], [T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                $i = self::classBodyEnd($tokens, $i);
                $markedFrom = null;
                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            // `Foo::class` emits T_CLASS too, and treating it as a declaration
            // invented a class range that could exempt heredocs nowhere near a
            // catalog prompt.
            $previous = self::previousMeaningfulToken($tokens, $i);
            if (\is_array($previous) && $previous[0] === T_DOUBLE_COLON) {
                continue;
            }

            $end = self::classBodyEnd($tokens, $i);
            if ($markedFrom !== null) {
                $ranges[] = [$markedFrom, $end];
            } else {
                // The interface may be named after the `class` token; re-check
                // the declaration this class opens.
                for ($j = $i + 1; $j < $end; $j++) {
                    $candidate = $tokens[$j];
                    if (!\is_array($candidate)) {
                        if (self::text($candidate) === '{') {
                            break;
                        }
                        continue;
                    }
                    if ($candidate[0] === T_IMPLEMENTS) {
                        continue;
                    }
                    if (self::resolves($candidate[1], self::INTERFACE_FQCN, $aliases, $namespace)) {
                        $ranges[] = [$i, $end];
                        break;
                    }
                }
            }

            $markedFrom = null;
            $i = $end;
        }

        return $ranges;
    }

    /**
     * The token index of a class body's closing brace.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function classBodyEnd(array $tokens, int $classToken): int
    {
        $count = \count($tokens);
        $depth = 0;
        $opened = false;

        for ($i = $classToken; $i < $count; $i++) {
            $text = self::text($tokens[$i]);
            if ($text === '{') {
                $depth++;
                $opened = true;
                continue;
            }
            if ($text === '}') {
                $depth--;
                if ($opened && $depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    /**
     * @param list<array{0: int, 1: int}> $ranges
     */
    public static function within(array $ranges, int $index): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($index >= $from && $index <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the attribute group at $index attach to a class declaration?
     *
     * Looks past the group's closing bracket, then past any further attribute
     * groups and declaration modifiers, and requires `class` to be what follows.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function marksAClass(array $tokens, int $index): bool
    {
        $count = \count($tokens);
        $depth = 0;

        for ($i = $index; $i < $count; $i++) {
            $token = $tokens[$i];

            if (\is_array($token)) {
                if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($depth > 0) {
                    continue;
                }
                if ($token[0] === T_ATTRIBUTE) {
                    $depth++;
                    continue;
                }
                if (\in_array($token[0], [T_FINAL, T_ABSTRACT, T_READONLY], true)) {
                    continue;
                }

                return $token[0] === T_CLASS;
            }

            $text = self::text($token);
            if ($text === '[' || $text === '(') {
                $depth++;
                continue;
            }
            if ($text === ']' || $text === ')') {
                $depth--;
                continue;
            }
            if ($depth === 0) {
                return false;
            }
        }

        return false;
    }

    /**
     * Every attribute name in one `#[...]` group.
     *
     * PHP allows several in a group — `#[Other, AsPrompt(id: 'x')]` — so reading
     * only the first token missed the declaration and reported a real prompt
     * class for the body it is supposed to have. Argument lists are skipped by
     * depth, since a name inside `Other(AsPrompt::class)` is an argument, not an
     * applied attribute.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return list<string>
     */
    private static function attributeNames(array $tokens, int $index): array
    {
        $names = [];
        $depth = 0;
        $expectName = true;
        $count = \count($tokens);

        for ($i = $index + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!\is_array($token)) {
                $literal = self::text($token);
                if ($literal === '(' || $literal === '[') {
                    $depth++;
                    continue;
                }
                if ($literal === ')') {
                    $depth--;
                    continue;
                }
                if ($literal === ']') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                    continue;
                }
                if ($literal === ',' && $depth === 0) {
                    $expectName = true;
                }
                continue;
            }

            if (\in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if ($depth === 0 && $expectName) {
                $names[] = $token[1];
                $expectName = false;
            }
        }

        return $names;
    }

    /**
     * Does $name, as written in this file, refer to $fqcn?
     *
     * Resolved through the file's own imports rather than by short name, because
     * a short name is not an identity: `use Vendor\Ui\AsPrompt as CatalogPrompt;`
     * is a DIFFERENT attribute, and exempting a file for it would hide every real
     * prompt heredoc in that service. Imports carry the full namespace for
     * exactly this comparison.
     *
     * A bare short name with no matching import is resolved against the file's
     * OWN namespace, the way PHP resolves it — so an unrelated project-local
     * `#[AsPrompt]` is `App\Social\AsPrompt`, not the catalog attribute, and
     * exempts nothing. Treating every unimported short name as the catalog's was
     * a way to hide a real SYSTEM_PROMPT heredoc behind a same-named class.
     *
     * @param array<string, string> $imports lower-cased local name => fully-qualified symbol
     */
    private static function resolves(string $name, string $fqcn, array $imports, string $namespace = ''): bool
    {
        // PHP resolves class, interface and alias names case-insensitively, so
        // `#[catalogprompt]` is the same declaration as `#[CatalogPrompt]` and an
        // exact-case comparison reported the catalog's own implementation as a
        // hand-rolled copy of itself.
        // A LEADING backslash means fully qualified; without one the name is
        // relative to the current namespace, and stripping it first lost that
        // distinction — so `#[Semitexa\Prompt\Attribute\AsPrompt]` inside
        // `namespace App;` was read as the catalog attribute when PHP resolves
        // it to `App\Semitexa\Prompt\Attribute\AsPrompt`.
        $fullyQualified = str_starts_with($name, '\\');
        $name = ltrim($name, '\\');
        $key = strtolower($name);

        if (!$fullyQualified && isset($imports[$key])) {
            return strcasecmp($imports[$key], $fqcn) === 0;
        }

        if ($fullyQualified) {
            return strcasecmp($name, $fqcn) === 0;
        }

        if (str_contains($name, '\\')) {
            // Fully qualified as written, or qualified through an imported
            // prefix (`use Semitexa\Prompt; ... #[Prompt\Attribute\AsPrompt]`).
            // Qualified but relative: the first segment may be an imported
            // prefix (`use Semitexa\Prompt; ... #[Prompt\Attribute\AsPrompt]`),
            // otherwise the whole thing hangs off the current namespace.
            $segments = explode('\\', $name);
            $first = strtolower((string) array_shift($segments));

            // PHP's own `namespace\Name` operator means "the current namespace",
            // so the keyword is a pointer, not a segment — appending it built
            // `Semitexa\Prompt\Attribute\namespace\AsPrompt` and stopped a
            // genuine declaration from resolving.
            if ($first === 'namespace') {
                $rest = implode('\\', $segments);
                $resolved = $namespace === '' ? $rest : $namespace . '\\' . $rest;

                return strcasecmp($resolved, $fqcn) === 0;
            }

            if (isset($imports[$first])) {
                $resolved = $imports[$first] . '\\' . implode('\\', $segments);
            } else {
                $resolved = $namespace === '' ? $name : $namespace . '\\' . $name;
            }

            return strcasecmp($resolved, $fqcn) === 0;
        }

        $resolved = $namespace === '' ? $name : $namespace . '\\' . $name;

        return strcasecmp($resolved, $fqcn) === 0;
    }

    /**
     * The namespace named by the T_NAMESPACE token at $index, or '' for the
     * global one.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     */
    private static function namespaceAt(array $tokens, int $index): string
    {
        $count = \count($tokens);
        for ($j = $index + 1; $j < $count; $j++) {
            $candidate = $tokens[$j];
            if (\is_array($candidate)) {
                // Comments too: `namespace /* here */ App;` otherwise made the
                // comment text the namespace, and every declaration in the file
                // then resolved against nonsense.
                if (\in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                return trim($candidate[1], '\\');
            }

            // `namespace {` — the global namespace, written as a block.
            return '';
        }

        return '';
    }

    /**
     * The local names introduced by the ONE `use` statement at $index, mapped
     * to their fully qualified symbol.
     *
     * One statement can introduce several names —
     * `use A\B\{AsPrompt as CatalogPrompt, Other};` and
     * `use A\B as X, C\D as Y;` — so this walks each statement to its `;`
     * instead of stopping at the first alias it finds. A grouped import that
     * aliased the attribute previously yielded no alias at all, and the class
     * using it was reported for hand-rolling the mechanism it implements.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * Plain imports are recorded too, not only aliased ones: `use Vendor\Ui\AsPrompt;`
     * makes the short name mean something other than the catalog attribute, and
     * only the full namespace can tell the two apart.
     *
     * @return array<string, string> lower-cased local name => fully-qualified symbol
     */
    private static function importsAt(array $tokens, int $i): array
    {
        $aliases = [];
        $count = \count($tokens);

        {
            // One entry at a time: the last name seen is what an `as` renames,
            // and a comma or a brace ends the entry without ending the statement.
            $lastName = null;
            $expectAlias = false;
            $prefix = '';

            // `use function ...` and `use const ...` import symbols in other
            // namespaces entirely: neither affects how a class or attribute name
            // resolves. Recording them as class aliases let an unrelated
            // `#[CatalogPrompt]` suppress a real prompt heredoc.
            //
            // PHP puts the kind in two places, and they have DIFFERENT reach:
            //
            //   use function A\helper, B\Other;      statement-level: every
            //                                        comma-separated entry
            //   use A\B\{function c, D};             per entry: only that one
            //
            // A single flag reset at every entry boundary gets the second shape
            // right and the first one wrong — the comma cleared the statement's
            // own kind and the next entry was recorded as a class. So the group
            // is tracked, and a kind seen before it ends the statement outright.
            $inGroup = false;
            $skipEntry = false;

            $record = static function (?string $symbol, ?string $local) use (&$aliases, &$skipEntry): void {
                if ($symbol === null || $skipEntry) {
                    return;
                }
                // Keyed lower-case: PHP matches these names case-insensitively.
                $aliases[strtolower($local ?? self::shortNameOf($symbol))] = $symbol;
            };

            for ($j = $i + 1; $j < $count; $j++) {
                $candidate = $tokens[$j];

                if (!\is_array($candidate)) {
                    $literal = self::text($candidate);
                    if ($literal === ';') {
                        if (!$expectAlias) {
                            $record($lastName === null ? null : self::join($prefix, $lastName), null);
                        }
                        break;
                    }
                    if ($literal === '{') {
                        // Everything before the brace was the group prefix.
                        $prefix = $lastName ?? '';
                        $lastName = null;
                        $expectAlias = false;
                        $skipEntry = false;
                        $inGroup = true;
                        continue;
                    }
                    if ($literal === ',' || $literal === '}') {
                        if (!$expectAlias) {
                            $record($lastName === null ? null : self::join($prefix, $lastName), null);
                        }
                        if ($literal === '}') {
                            $prefix = '';
                            $inGroup = false;
                        }
                        $lastName = null;
                        $expectAlias = false;
                        $skipEntry = false;
                    }
                    continue;
                }

                if (\in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR], true)) {
                    continue;
                }

                if (\in_array($candidate[0], [T_FUNCTION, T_CONST], true)) {
                    if (!$inGroup) {
                        // Statement-level: the whole `use` imports symbols, so
                        // nothing in it can alias a class.
                        return [];
                    }
                    $skipEntry = true;
                    continue;
                }

                if ($candidate[0] === T_AS) {
                    $expectAlias = true;
                    continue;
                }

                if ($expectAlias && $lastName !== null) {
                    $record(self::join($prefix, $lastName), $candidate[1]);
                    $lastName = null;
                    $expectAlias = false;
                    continue;
                }

                // A grouped import's prefix and its entries arrive as separate
                // name tokens; the entry is the one an `as` can rename, so the
                // most recent name wins.
                $lastName = $candidate[1];
            }
        }

        return $aliases;
    }

    private static function join(string $prefix, string $name): string
    {
        $prefix = trim($prefix, '\\');

        return $prefix === '' ? ltrim($name, '\\') : $prefix . '\\' . ltrim($name, '\\');
    }


    /**
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens
     * @return array{0: int, 1: string, 2: int}|string|null
     */
    private static function previousMeaningfulToken(array $tokens, int $index): array|string|null
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $token = $tokens[$i];
            if (\is_array($token) && \in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }

    private static function shortNameOf(string $fqcn): string
    {
        $short = strrchr($fqcn, '\\');

        return $short === false ? $fqcn : substr($short, 1);
    }

    /** @param array{0: int, 1: string, 2: int}|string $token */
    private static function text(array|string $token): string
    {
        return \is_array($token) ? $token[1] : $token;
    }
}
