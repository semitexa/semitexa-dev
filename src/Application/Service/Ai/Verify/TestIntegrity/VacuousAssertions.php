<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity;

/**
 * Assertions an empty or missing value would satisfy (tk-verify-catches-vacuous-assertions).
 *
 * A check that something is absent (assertNotSame, assertStringNotContainsString,
 * assertArrayNotHasKey, an upper bound on a size) passes on '' or [] just as it passes on the
 * right value. When no other assertion of the same test shows the value to hold anything, a value
 * that broke into nothing passes the test. This was the most common CodeRabbit finding on
 * 2026-11-12: an unreadable file cast to '', a json_encode() false cast to '', a loop over a list
 * that came back empty, a constant list emptied.
 *
 * The value is the assertion's subject argument. A subject that is a variable (or a path into
 * one, or strlen()/count()/a cast of one) is shown when one of its variables appears in an
 * assertion that an empty value fails: not another check for absence, not a type or existence
 * check (assertIsString, assertNotNull), not a value pinned empty (assertCount(0, ...)). A subject
 * that calls something, or reads a constant, is shown only by such an assertion on the same
 * expression. `assertNotSame('', $x)` and `assertNotCount(0, $x)` are the showing, not the problem.
 *
 * Inside a foreach, the loop is the first question: a check in a loop over a computed list that
 * no assertion outside the loop names as a whole runs zero times on an empty list. A loop value
 * still needs evidence of its own: a non-empty list may hold empty rows (review, dev#247).
 * Evidence under an `if`, in a `catch`, a loop or a closure counts only for checks under it.
 *
 * A heuristic, not a proof. Not modelled: a ternary or `&&` around an assertion, an early
 * return, evidence asserted in a helper method, a value whose emptiness is ruled out by its type.
 * A finding it gets wrong is accepted with a reason on an added line, like every finding of
 * lint:test-integrity.
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
     * only against a fixed expectation (`assertNotSame('x', $y)`), not against another computed
     * value (`assertNotSame($a, $b)`).
     */
    private const COMPARISONS = ['assertnotsame', 'assertnotequals', 'assertnotequalscanonicalizing', 'assertnotequalsignoringcase', 'assertjsonstringnotequalsjsonstring'];

    /** Upper bounds: vacuous on a size (strlen, count), not on a duration or a number of attempts. */
    private const UPPER_BOUNDS = ['assertlessthan', 'assertlessthanorequal'];

    private const SIZES = ['strlen', 'mb_strlen', 'count', 'sizeof', 'filesize'];

    /** Absence checks that, against an empty expectation, show the value holds something. */
    private const NOT_EMPTY_AGAINST_EMPTY = ['assertnotsame', 'assertnotequals', 'assertnotcount'];

    /**
     * Assertions an empty value passes too: they show the type or that the value exists, not that
     * it holds anything (review, dev#247). `assertContainsOnly*` counts by prefix.
     */
    private const NO_EVIDENCE = [
        'assertisstring', 'assertisarray', 'assertisiterable', 'assertisscalar', 'assertisobject', 'assertnotnull',
        'assertnotfalse', 'assertinstanceof', 'assertempty', 'assertnull', 'assertfalse', 'assertisbool',
        'assertisnotarray', 'assertisnotstring', 'assertislist',
    ];

    /** Value checks that, against an empty expectation, pin the value empty. */
    private const PINNED = ['assertsame', 'assertequals', 'assertcount', 'assertgreaterthanorequal'];

    /** Taken off a subject before it is read: they keep an empty value empty. The value is their first argument. */
    private const WRAPPERS = ['strlen', 'mb_strlen', 'count', 'sizeof', 'trim', 'strtolower', 'array_keys', 'array_values', 'implode'];

    /** Empty values; null and false only where a value is pinned, since '' is neither. */
    private const EMPTY_LITERALS = ["''", '""', '[]', 'array()', '0'];

    private const NULLISH = ['null', 'false'];

    /**
     * @param list<int>|null $addedLines numbers of the lines the change wrote, null for all: only an
     *                                   assertion the change wrote or rewrote is its finding
     * @return list<TestChangeFinding>
     */
    public static function in(string $path, string $source, ?array $addedLines = null): array
    {
        $added = $addedLines === null ? null : array_fill_keys($addedLines, true);
        $tokens = array_values(array_filter(\PhpToken::tokenize($source), static fn (\PhpToken $t): bool => !$t->isIgnorable()));
        $findings = [];
        foreach (self::methods($tokens) as $method => ['calls' => $calls, 'loops' => $loops, 'fixed' => $fixed, 'branches' => $branches]) {
            foreach ($calls as $call) {
                $name = strtolower($call['name']);
                if (!$call['absence'] || !isset($call['args'][1]) || self::againstEmpty($call)) {
                    continue;
                }
                // assertNotSame(null, $x) is assertNotNull: an existence check, neither evidence nor a finding.
                if (in_array($name, self::COMPARISONS, true) && (!self::isFixed($call['args'][0]) || self::isEmptyLiteral($call['args'][0], true))) {
                    continue;
                }
                if (in_array($name, self::UPPER_BOUNDS, true) && !in_array(strtolower(ltrim(self::uncast($call['args'][1])[0]->text ?? '', '\\')), self::SIZES, true)) {
                    continue;
                }
                // Any line of the call: a rewritten subject on its own line is the change (review, dev#247).
                if ($added !== null && array_intersect_key($added, array_flip(range($call['line'], $call['end']))) === []) {
                    continue;
                }
                $why = self::unshownLoop($call, $calls, $loops, $fixed, $branches) ?? self::unshownSubject($call, $calls, $branches);
                if ($why === null) {
                    continue;
                }
                $findings[] = new TestChangeFinding(TestChangeFinding::VACUOUS_ASSERTION, $path, $method, sprintf(
                    '%s(), line %d: %s(%s) passes when %s — assert it first (assertNotSame(\'\', ...), assertCount, assertJson)',
                    $method,
                    $call['line'],
                    $call['name'],
                    self::text($call['args'][1]),
                    $why,
                ));
            }
        }

        return $findings;
    }

    /**
     * The loop around the call whose list nothing outside it shows is non-empty.
     *
     * @param array{at: int} $call
     * @param list<array{at: int, absence: bool, name: string, args: list<list<\PhpToken>>}> $calls
     * @param list<array{from: int, to: int, list: list<\PhpToken>, value: ?string}> $loops
     * @param array<string, true> $fixed variables the method assigns a non-empty literal array
     * @param list<array{0: int, 1: int}> $branches token ranges that may not run
     */
    private static function unshownLoop(array $call, array $calls, array $loops, array $fixed, array $branches): ?string
    {
        foreach ($loops as $loop) {
            if ($call['at'] > $loop['from'] && $call['at'] < $loop['to'] && !self::listShown($loop, $calls, $fixed, $branches)) {
                return sprintf('the loop over %s runs zero times, and no assertion outside it shows that list holds anything', self::text($loop['list']));
            }
        }

        return null;
    }

    /**
     * @param array{at: int, args: list<list<\PhpToken>>} $call
     * @param list<array{at: int, absence: bool, name: string, args: list<list<\PhpToken>>}> $calls
     * @param list<array{0: int, 1: int}> $branches
     */
    private static function unshownSubject(array $call, array $calls, array $branches): ?string
    {
        $subject = self::unwrap($call['args'][1]);
        if ($subject === [] || self::isLiteral($subject)) {
            return null;
        }
        // A loop value needs its own evidence: assertNotEmpty($rows) passes on [[]], and so does a
        // check on each empty $row (review, dev#247).
        $shown = [];
        foreach ($calls as $other) {
            if (self::isEvidence($other) && self::runsWith($other['at'], $call['at'], $branches)) {
                array_push($shown, ...$other['args']);
            }
        }

        return self::isShown($subject, $shown) ? null : 'that value is empty or missing, and no other assertion shows it holds anything';
    }

    /**
     * Whether evidence at $evidence runs whenever the check at $target does: an assertion under an
     * `if`, in a `catch` or in a closure that the check is not under too may be skipped on exactly
     * the empty path (review, dev#247).
     *
     * @param list<array{0: int, 1: int}> $branches
     */
    private static function runsWith(int $evidence, int $target, array $branches): bool
    {
        foreach ($branches as [$from, $to]) {
            if ($evidence > $from && $evidence < $to && !($target > $from && $target < $to)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the loop's list is fixed, or named as a whole by an assertion outside the loop.
     *
     * @param array{from: int, to: int, list: list<\PhpToken>} $loop
     * @param list<array{at: int, absence: bool, name: string, args: list<list<\PhpToken>>}> $calls
     * @param array<string, true> $fixed
     * @param list<array{0: int, 1: int}> $branches
     */
    private static function listShown(array $loop, array $calls, array $fixed, array $branches): bool
    {
        $list = self::text($loop['list']);
        if (self::isFixed($loop['list']) || isset($fixed[$list])) {
            return true;
        }
        foreach ($calls as $other) {
            if (($other['at'] > $loop['from'] && $other['at'] < $loop['to']) || !self::isEvidence($other) || !self::runsWith($other['at'], $loop['from'], $branches)) {
                continue;
            }
            foreach ($other['args'] as $arg) {
                if (self::text(self::unwrap($arg)) === $list) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Every assertion call and foreach of every method.
     *
     * @param list<\PhpToken> $tokens
     * @return array<string, array{
     *     calls: list<array{at: int, name: string, line: int, end: int, absence: bool, args: list<list<\PhpToken>>}>,
     *     loops: list<array{from: int, to: int, list: list<\PhpToken>, value: ?string}>,
     *     fixed: array<string, true>,
     *     branches: list<array{0: int, 1: int}>
     * }>
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
            while ($j < $count && !self::is($tokens[$j], '{') && !self::is($tokens[$j], ';')) {
                $j++;
            }
            if ($j >= $count || self::is($tokens[$j], ';')) {
                continue;
            }
            $end = self::closing($tokens, $j);
            $method = $methods[$name] ?? ['calls' => [], 'loops' => [], 'fixed' => [], 'branches' => []];
            for ($k = $j + 1; $k < $end; $k++) {
                $token = $tokens[$k];
                // `$rows = ['a']` is a list that holds something; `$rows = []` is not (review, dev#247).
                $literal = $tokens[$k + 2] ?? $token;
                if ($token->id === T_VARIABLE && self::is($tokens[$k + 1] ?? $token, '=')
                    && ((self::is($literal, '[') && !self::is($tokens[$k + 3] ?? $token, ']'))
                        || (strtolower($literal->text) === 'array' && !self::is($tokens[$k + 4] ?? $token, ')')))) {
                    $method['fixed'][$token->text] = true;
                }
                if (in_array($token->id, [T_IF, T_ELSEIF, T_ELSE, T_SWITCH, T_CATCH, T_WHILE, T_FOR, T_FOREACH, T_FUNCTION], true)) {
                    $branch = self::branch($tokens, $k);
                    if ($branch !== null) {
                        $method['branches'][] = $branch;
                    }
                }
                if ($token->id === T_FOREACH && self::is($tokens[$k + 1] ?? $token, '(')) {
                    $method['loops'][] = self::loop($tokens, $k + 1);
                    continue;
                }
                $call = strtolower($token->text);
                if ($token->id !== T_STRING || !self::is($tokens[$k + 1] ?? $token, '(')
                    || !in_array($tokens[$k - 1]->id, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NULLSAFE_OBJECT_OPERATOR], true)
                    || !(str_starts_with($call, 'assert') || str_starts_with($call, 'expect'))) {
                    continue;
                }
                $close = self::closing($tokens, $k + 1);
                $method['calls'][] = [
                    'at'      => $k,
                    'name'    => $token->text,
                    'line'    => $token->line,
                    'end'     => $tokens[$close]->line ?? $token->line,
                    'absence' => in_array($call, self::ABSENCE, true),
                    'args'    => self::arguments(array_slice($tokens, $k + 1, $close - $k)),
                ];
            }
            $methods[$name] = $method;
            $i = $end;
        }

        return $methods;
    }

    /**
     * The body of an `if`, `else`, `catch`, `switch`, a loop or a closure: code that may not run.
     *
     * @param list<\PhpToken> $tokens
     * @return array{0: int, 1: int}|null
     */
    private static function branch(array $tokens, int $keyword): ?array
    {
        $k = $keyword + 1;
        if (self::is($tokens[$k] ?? $tokens[$keyword], '(')) {
            $k = self::closing($tokens, $k) + 1;
        }
        // A closure's `use (...)` and return type come before its body.
        while ($tokens[$keyword]->id === T_FUNCTION && $k < count($tokens) && !self::is($tokens[$k], '{') && !self::is($tokens[$k], ';')) {
            $k++;
        }
        if (!self::is($tokens[$k] ?? $tokens[$keyword], '{')) {
            if ($tokens[$keyword]->id === T_FUNCTION || ($tokens[$k]->id ?? null) === T_IF) {
                return null; // `else if`: the `if` is its own branch
            }
            for ($to = $k; $to < count($tokens) && !self::is($tokens[$to], ';'); $to++) {
            }

            return [$k - 1, $to + 1];
        }

        return [$k, self::closing($tokens, $k)];
    }

    /**
     * `foreach (<list> as [$key =>] $value) { ... }`: the list, the value, and the body's token range.
     *
     * @param list<\PhpToken> $tokens
     * @return array{from: int, to: int, list: list<\PhpToken>, value: ?string}
     */
    private static function loop(array $tokens, int $open): array
    {
        $close = self::closing($tokens, $open);
        $head = array_slice($tokens, $open + 1, $close - $open - 1);
        $as = 0;
        while ($as < count($head) && $head[$as]->id !== T_AS) {
            $as++;
        }
        $value = null;
        foreach (array_slice($head, $as + 1) as $token) {
            if ($token->id === T_VARIABLE) {
                $value = $token->text;
            }
        }
        // A body without braces is one statement: it ends at the next `;`.
        $body = $close + 1;
        if (self::is($tokens[$body] ?? $tokens[$close], '{')) {
            $to = self::closing($tokens, $body);
        } else {
            for ($to = $body; $to < count($tokens) && !self::is($tokens[$to], ';'); $to++) {
            }
        }

        return ['from' => $close, 'to' => $to, 'list' => array_slice($head, 0, $as), 'value' => $value];
    }

    /**
     * The index of the token closing the bracket at $open, matched by token id: a `}` inside a
     * string ("{\"id\":$id}") is string content, not a bracket (review, dev#247).
     *
     * @param list<\PhpToken> $tokens
     */
    private static function closing(array $tokens, int $open): int
    {
        $depth = 0;
        for ($k = $open, $count = count($tokens); $k < $count; $k++) {
            if (self::opens($tokens[$k])) {
                $depth++;
            } elseif (self::is($tokens[$k], ')') || self::is($tokens[$k], ']') || self::is($tokens[$k], '}')) {
                if (--$depth === 0) {
                    return $k;
                }
            }
        }

        return $count - 1;
    }

    /**
     * The arguments of a call, given from its `(` to its `)`, split at the top level.
     *
     * @param list<\PhpToken> $call
     * @return list<list<\PhpToken>>
     */
    private static function arguments(array $call): array
    {
        $args = [[]];
        $depth = 0;
        foreach (array_slice($call, 1, -1) as $token) {
            if (self::opens($token)) {
                $depth++;
            } elseif (self::is($token, ')') || self::is($token, ']') || self::is($token, '}')) {
                $depth--;
            } elseif ($depth === 0 && self::is($token, ',')) {
                $args[] = [];
                continue;
            }
            $args[count($args) - 1][] = $token;
        }

        return array_values(array_filter($args, static fn (array $arg): bool => $arg !== []));
    }

    /**
     * The subject without casts and the wrappers that keep empty empty. Only a wrapper that is the
     * whole subject: `strlen($a) + strlen($b)` stays as it is.
     *
     * @param list<\PhpToken> $arg
     * @return list<\PhpToken>
     */
    private static function unwrap(array $arg): array
    {
        while (($arg = self::uncast($arg)) !== []) {
            $function = strtolower(ltrim($arg[0]->text, '\\'));
            if (!in_array($function, self::WRAPPERS, true) || !self::is($arg[1] ?? $arg[0], '(') || self::closing($arg, 1) !== count($arg) - 1) {
                break;
            }
            $inner = self::arguments(array_slice($arg, 1));
            // implode(', ', $x) keeps $x; trim($x, "\n") and mb_strlen($x, 'UTF-8') keep $x.
            $arg = $inner === [] ? [] : ($function === 'implode' ? $inner[count($inner) - 1] : $inner[0]);
        }

        return $arg;
    }

    /**
     * @param list<\PhpToken> $arg
     * @return list<\PhpToken>
     */
    private static function uncast(array $arg): array
    {
        while ($arg !== [] && in_array($arg[0]->id, [T_STRING_CAST, T_ARRAY_CAST, T_INT_CAST], true)) {
            array_shift($arg);
        }

        return $arg;
    }

    /**
     * `assertNotSame('', $x)`, `assertNotCount(0, $x)`: the check that a value holds something.
     * An empty needle or key (`assertArrayNotHasKey(0, $x)`) is not, nor `assertNotSame(null, $x)`,
     * which '' passes (review, dev#247).
     *
     * @param array{name: string, args: list<list<\PhpToken>>} $call
     */
    private static function againstEmpty(array $call): bool
    {
        return in_array(strtolower($call['name']), self::NOT_EMPTY_AGAINST_EMPTY, true) && self::isEmptyLiteral($call['args'][0] ?? [], false);
    }

    /**
     * Whether an assertion shows its values hold something: not a check for absence, not a type
     * or existence check, not a value pinned empty (`assertSame('', $x)`, `assertCount(0, $x)`).
     *
     * @param array{name: string, absence: bool, args: list<list<\PhpToken>>} $call
     */
    private static function isEvidence(array $call): bool
    {
        $name = strtolower($call['name']);
        if ($call['absence']) {
            return self::againstEmpty($call);
        }

        return !in_array($name, self::NO_EVIDENCE, true) && !str_starts_with($name, 'assertcontainsonly')
            && !(in_array($name, self::PINNED, true) && self::isEmptyLiteral($call['args'][0] ?? [], true));
    }

    /** @param list<\PhpToken> $arg */
    private static function isEmptyLiteral(array $arg, bool $orNullish): bool
    {
        $text = strtolower(self::text($arg));

        return in_array($text, self::EMPTY_LITERALS, true) || ($orNullish && in_array($text, self::NULLISH, true));
    }

    /**
     * A literal or a constant: nothing computed.
     *
     * @param list<\PhpToken> $arg
     */
    private static function isFixed(array $arg): bool
    {
        foreach ($arg as $token) {
            if ($token->id === T_VARIABLE || self::is($token, '(')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<\PhpToken> $subject */
    private static function isLiteral(array $subject): bool
    {
        foreach ($subject as $token) {
            if (!in_array($token->id, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true) && !self::is($token, '-') && !self::is($token, '.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether an argument of an assertion that shows something holds the subject: the subject
     * itself, or a part of it (a true `$tasks[0]['suggested']` shows `$tasks[0]` holds something).
     * Matched token by token, so `'$text'` in a string is not `$text`. Evidence on another part of
     * the value is not evidence, and neither is evidence on the whole: `assertSame('id', $record->id)`
     * shows nothing about `$record->summary`, nor `assertSame(['nested' => []], $config)` about
     * `$config['nested']` (review, dev#247).
     *
     * @param list<\PhpToken>       $subject
     * @param list<list<\PhpToken>> $shown arguments of the assertions that show a value holds something
     */
    private static function isShown(array $subject, array $shown): bool
    {
        $want = array_map(static fn (\PhpToken $t): string => $t->text, $subject);
        $length = count($want);
        foreach ($shown as $arg) {
            $have = array_map(static fn (\PhpToken $t): string => $t->text, $arg);
            for ($i = 0, $last = count($have) - $length; $i <= $last; $i++) {
                if (array_slice($have, $i, $length) === $want) {
                    return true;
                }
            }
        }

        return false;
    }

    /** A bracket that opens, by token id: `"{$x}"` opens with T_CURLY_OPEN and closes with a plain `}`. */
    private static function opens(\PhpToken $token): bool
    {
        return self::is($token, '(') || self::is($token, '[') || self::is($token, '{')
            || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES;
    }

    /** A one-character token: its id is the character, so string content never matches. */
    private static function is(\PhpToken $token, string $char): bool
    {
        return $token->id === ord($char);
    }

    /** @param list<\PhpToken> $tokens */
    private static function text(array $tokens): string
    {
        return implode('', array_map(static fn (\PhpToken $t): string => $t->text, $tokens));
    }
}
