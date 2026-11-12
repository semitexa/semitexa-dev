<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity;

/**
 * Assertions an empty or missing value would satisfy (tk-verify-catches-vacuous-assertions).
 *
 * A check that something is absent (assertNotSame, assertStringNotContainsString,
 * assertArrayNotHasKey, an upper bound) passes on '' or [] just as it passes on the right value.
 * When no other assertion of the same test shows the value to hold anything, a value that broke
 * into nothing passes the test. This was the most common CodeRabbit finding on 2026-11-12:
 * an unreadable file cast to '', a json_encode() false cast to '', a loop over a list that came
 * back empty, a constant list emptied.
 *
 * The value is the assertion's subject argument. A subject that is a variable (or a path into
 * one, or strlen()/count()/a cast of one) is shown when one of its variables appears in another
 * assertion that is not itself a check for absence. A subject that calls something, or reads a
 * constant, is shown only by an assertion on the same expression. A check against an empty
 * literal (`assertNotSame('', $x)`) is the showing, not the problem.
 */
final class VacuousAssertions
{
    /** Checks for absence, every one with the value as its second argument. */
    private const ABSENCE = [
        'assertnotsame', 'assertnotequals', 'assertnotequalscanonicalizing', 'assertnotequalsignoringcase',
        'assertnotcontains', 'assertnotcontainsequals', 'assertstringnotcontainsstring',
        'assertstringnotcontainsstringignoringcase', 'assertdoesnotmatchregularexpression', 'assertarraynothaskey',
        'assertobjectnothasproperty', 'assertnotcount', 'assertnotinstanceof', 'assertstringstartsnotwith',
        'assertstringendsnotwith', 'assertlessthan', 'assertlessthanorequal', 'assertstringnotmatchesformat',
        'assertjsonstringnotequalsjsonstring',
    ];

    /**
     * Comparisons of two values: two empty values are equal and fail them, so they are vacuous
     * only against a fixed expectation (`assertLessThan(6000, strlen($json))`), not against another
     * computed value (`assertNotSame($a, $b)`).
     */
    private const COMPARISONS = [
        'assertnotsame', 'assertnotequals', 'assertnotequalscanonicalizing', 'assertnotequalsignoringcase',
        'assertlessthan', 'assertlessthanorequal', 'assertjsonstringnotequalsjsonstring',
    ];

    /** Taken off a subject before it is read: they keep an empty value empty. */
    private const WRAPPERS = ['strlen', 'mb_strlen', 'count', 'trim', 'strtolower', 'implode', 'array_keys', 'array_values'];

    private const EMPTY_LITERALS = ["''", '""', '[]', 'null', 'false', '0', 'array()'];

    /**
     * @param list<string>|null $addedLines lines the change wrote, null for all: only an assertion
     *                                      written by the change is its finding
     * @return list<TestChangeFinding>
     */
    public static function in(string $path, string $source, ?array $addedLines = null): array
    {
        $added = $addedLines === null ? null : array_fill_keys(array_map('trim', $addedLines), true);
        $lines = preg_split('/\R/', $source) ?: [];
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $findings = [];
        foreach (self::methods($tokens) as $method => $calls) {
            $shown = [];
            foreach ($calls as $call) {
                if (!$call['absence'] || self::againstEmpty($call)) {
                    foreach ($call['args'] as $arg) {
                        $shown[] = $arg;
                    }
                }
            }
            foreach ($calls as $call) {
                if (!$call['absence'] || !isset($call['args'][1]) || self::againstEmpty($call)) {
                    continue;
                }
                if (in_array(strtolower($call['name']), self::COMPARISONS, true) && !self::isFixed($call['args'][0])) {
                    continue;
                }
                if ($added !== null && !isset($added[trim($lines[$call['line'] - 1] ?? '')])) {
                    continue;
                }
                $subject = self::unwrap($call['args'][1]);
                if ($subject === [] || self::isLiteral($subject) || self::isShown($subject, $shown)) {
                    continue;
                }
                $findings[] = new TestChangeFinding(TestChangeFinding::VACUOUS_ASSERTION, $path, $method, sprintf(
                    '%s(), line %d: %s(%s) passes when that value is empty or missing, and no other assertion shows it holds anything — assert it first (assertNotSame(\'\', ...), assertCount, assertJson)',
                    $method,
                    $call['line'],
                    $call['name'],
                    self::text($call['args'][1]),
                ));
            }
        }

        return $findings;
    }

    /**
     * Every assertion call of every method, with its arguments split at the top level.
     *
     * @param list<\PhpToken> $tokens
     * @return array<string, list<array{name: string, line: int, absence: bool, args: list<list<\PhpToken>>}>>
     */
    private static function methods(array $tokens): array
    {
        $methods = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_FUNCTION || ($tokens[$i + 1]->id ?? null) !== T_STRING) {
                continue;
            }
            $name = $tokens[$i + 1]->text;
            $j = $i + 2;
            while ($j < $count && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';') {
                $j++;
            }
            if ($j >= $count || $tokens[$j]->text === ';') {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < $count; $k++) {
                $text = $tokens[$k]->text;
                if ($text === '{' || $tokens[$k]->id === T_CURLY_OPEN || $tokens[$k]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($text === '}' && --$depth === 0) {
                    break;
                }
                $previous = $tokens[$k - 1]->id ?? null;
                $call = strtolower($text);
                if ($tokens[$k]->id !== T_STRING || ($tokens[$k + 1]->text ?? '') !== '('
                    || !in_array($previous, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)
                    || !(str_starts_with($call, 'assert') || str_starts_with($call, 'expect'))) {
                    continue;
                }
                $methods[$name][] = [
                    'name'    => $text,
                    'line'    => $tokens[$k]->line,
                    'absence' => in_array($call, self::ABSENCE, true),
                    'args'    => self::arguments($tokens, $k + 1),
                ];
            }
            $i = $k;
        }

        return $methods;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return list<list<\PhpToken>>
     */
    private static function arguments(array $tokens, int $open): array
    {
        $args = [[]];
        $depth = 0;
        for ($k = $open, $count = count($tokens); $k < $count; $k++) {
            $text = $tokens[$k]->text;
            if (in_array($text, ['(', '[', '{'], true) || $tokens[$k]->id === T_CURLY_OPEN) {
                if ($depth++ === 0) {
                    continue;
                }
            } elseif (in_array($text, [')', ']', '}'], true)) {
                if (--$depth === 0) {
                    break;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $tokens[$k];
        }

        return array_values(array_filter($args, static fn (array $arg): bool => $arg !== []));
    }

    /**
     * The subject without casts and the wrappers that keep empty empty.
     *
     * @param list<\PhpToken> $arg
     * @return list<\PhpToken>
     */
    private static function unwrap(array $arg): array
    {
        while ($arg !== []) {
            if (in_array($arg[0]->id, [T_STRING_CAST, T_ARRAY_CAST, T_INT_CAST], true)) {
                array_shift($arg);
                continue;
            }
            $last = $arg[count($arg) - 1]->text;
            if (count($arg) > 3 && in_array(strtolower(ltrim($arg[0]->text, '\\')), self::WRAPPERS, true) && $arg[1]->text === '(' && $last === ')') {
                // The last argument: implode(", ", $x) keeps $x.
                $inner = self::arguments($arg, 1);
                $arg = $inner === [] ? [] : $inner[count($inner) - 1];
                continue;
            }
            break;
        }

        return $arg;
    }

    /** `assertNotSame('', $x)`: the check that a value holds something. @param array{args: list<list<\PhpToken>>} $call */
    private static function againstEmpty(array $call): bool
    {
        return in_array(strtolower(self::text($call['args'][0] ?? [])), self::EMPTY_LITERALS, true);
    }

    /**
     * A literal or a constant: nothing computed.
     *
     * @param list<\PhpToken> $arg
     */
    private static function isFixed(array $arg): bool
    {
        foreach ($arg as $token) {
            if ($token->id === T_VARIABLE || $token->text === '(') {
                return false;
            }
        }

        return true;
    }

    /** @param list<\PhpToken> $subject */
    private static function isLiteral(array $subject): bool
    {
        foreach ($subject as $token) {
            if (!in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true) && !in_array($token->text, ['-', '.'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<\PhpToken>       $subject
     * @param list<list<\PhpToken>> $shown arguments of the assertions that are not checks for absence
     */
    private static function isShown(array $subject, array $shown): bool
    {
        $calls = false;
        $variables = [];
        foreach ($subject as $token) {
            if ($token->id === T_VARIABLE && $token->text !== '$this') {
                $variables[$token->text] = true;
            }
            if ($token->text === '(' || $token->id === T_DOUBLE_COLON) {
                $calls = true;
            }
        }
        if ($calls || $variables === []) {
            $text = self::text($subject);
            foreach ($shown as $arg) {
                if (str_contains(self::text($arg), $text)) {
                    return true;
                }
            }

            return false;
        }
        foreach ($shown as $arg) {
            foreach ($arg as $token) {
                if (isset($variables[$token->text])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<\PhpToken> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(static fn (\PhpToken $t): string => $t->text, $tokens));
    }
}
