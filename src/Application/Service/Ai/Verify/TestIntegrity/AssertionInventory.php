<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity;

/**
 * What a test file checks, method by method: every assertion call and every
 * skip, read with the tokenizer (the file is never loaded — a test that
 * fatals on load must not take the gate down with it).
 *
 * The unit is the method, not the file, because that is where a weakening
 * hides: a file can keep its assertion count while one test loses its only
 * real check to an `assertNotNull`.
 */
final class AssertionInventory
{
    /**
     * Assertions that pin a value. Losing one of these while the method keeps
     * a weaker check is the "test still green, now asserts nothing" shape.
     */
    private const STRONG = [
        'assertsame', 'assertnotsame', 'assertequals', 'assertnotequals', 'assertequalscanonicalizing',
        'assertequalsignoringcase', 'assertequalswithdelta', 'assertcount', 'assertnotcount',
        'assertsamesize', 'assertmatchesregularexpression', 'assertstringequalsfile', 'assertfileequals',
        'assertjsonstringequalsjsonstring', 'assertjsonstringequalsjsonfile', 'assertstringcontainsstring',
        'assertstringnotcontainsstring', 'assertstringstartswith', 'assertstringendswith', 'assertcontains',
        'assertnotcontains', 'assertarrayhaskey', 'assertarraynothaskey', 'assertinstanceof',
        'assertxmlstringequalsxmlstring', 'assertobjectequals', 'expectexception', 'expectexceptionmessage',
        'expectexceptioncode', 'expectexceptionmessagematches', 'expectoutputstring', 'expectoutputregex',
    ];

    private const OTHER_CHECKS = ['fail', 'expects', 'addtoassertioncount'];

    private const SKIPS = ['marktestskipped', 'marktestincomplete'];

    /**
     * @return array<string, array{strong: int, total: int, skips: int}> method name => counts
     */
    public static function of(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->isIgnorable(),
        ));

        $methods = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== T_FUNCTION || ($tokens[$i + 1]->id ?? null) !== T_STRING) {
                continue;
            }
            $name = $tokens[$i + 1]->text;
            // The body: from the first `{` after the signature to its match.
            // An abstract or interface method has `;` first and no body.
            $j = $i + 2;
            while ($j < $count && $tokens[$j]->text !== '{' && $tokens[$j]->text !== ';') {
                $j++;
            }
            if ($j >= $count || $tokens[$j]->text === ';') {
                continue;
            }
            $depth = 0;
            $counts = ['strong' => 0, 'total' => 0, 'skips' => 0];
            for ($k = $j; $k < $count; $k++) {
                $text = $tokens[$k]->text;
                if ($text === '{' || $tokens[$k]->id === T_CURLY_OPEN || $tokens[$k]->id === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                } elseif ($text === '}') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
                if ($tokens[$k]->id !== T_STRING || ($tokens[$k + 1]->text ?? '') !== '(') {
                    continue;
                }
                $previous = $tokens[$k - 1]->id ?? null;
                if ($previous !== T_OBJECT_OPERATOR && $previous !== T_DOUBLE_COLON && $previous !== T_NULLSAFE_OBJECT_OPERATOR) {
                    continue;
                }
                $call = strtolower($text);
                if (in_array($call, self::SKIPS, true)) {
                    $counts['skips']++;
                } elseif (str_starts_with($call, 'assert') || str_starts_with($call, 'expect') || in_array($call, self::OTHER_CHECKS, true)) {
                    $counts['total']++;
                    if (in_array($call, self::STRONG, true)) {
                        $counts['strong']++;
                    }
                }
            }
            // Two classes in one file may each have a method of this name:
            // their checks add up, or a loss in one hides behind the other.
            if (isset($methods[$name])) {
                foreach ($counts as $key => $value) {
                    $counts[$key] = $value + $methods[$name][$key];
                }
            }
            $methods[$name] = $counts;
            $i = $k;
        }

        return $methods;
    }
}
