<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * Says whether a PHP source declares a scheduled job: a class that carries
 * `#[AsScheduledJob]` or implements `ScheduledJobInterface`.
 *
 * A job is recognised by what the class declares, never by its basename: a
 * job need not end in `Job.php`, and a `*Job.php` file may be a queue message.
 * The source is tokenised, so the attribute quoted in a docblock or shown in
 * a string (the scheduler's own capability example) is not a job.
 */
final class ScheduledJobDeclaration
{
    private const ATTRIBUTE = 'AsScheduledJob';
    private const CONTRACT  = 'ScheduledJobInterface';

    public static function in(string $source): bool
    {
        // Most files name neither: they are never tokenised.
        if (!str_contains($source, self::ATTRIBUTE) && !str_contains($source, self::CONTRACT)) {
            return false;
        }

        try {
            $tokens = array_values(\PhpToken::tokenize($source));
        } catch (\Throwable) {
            return false;
        }

        $count = count($tokens);
        $attributeNames = self::namesOf($tokens, self::ATTRIBUTE);
        $contractNames  = self::namesOf($tokens, self::CONTRACT);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === \T_ATTRIBUTE) {
                // Up to the bracket that closes the attribute group. Only a name
                // at the group's own level is an attribute: inside parentheses
                // it is an argument (#[Handles(AsScheduledJob::class)]).
                $depth = 1;
                $parens = 0;
                for ($j = $i + 1; $j < $count && $depth > 0; $j++) {
                    $text = $tokens[$j]->text;
                    if ($text === '[' || $tokens[$j]->id === \T_ATTRIBUTE) {
                        $depth++;
                    } elseif ($text === ']') {
                        $depth--;
                    } elseif ($text === '(') {
                        $parens++;
                    } elseif ($text === ')') {
                        $parens--;
                    } elseif ($parens === 0 && $depth === 1 && self::names($tokens[$j], $attributeNames)) {
                        return true;
                    }
                }
                continue;
            }

            if ($token->id === \T_IMPLEMENTS) {
                for ($j = $i + 1; $j < $count && $tokens[$j]->text !== '{'; $j++) {
                    if (self::names($tokens[$j], $contractNames)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * The short name itself, plus every alias a `use ... as Alias` gives it.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return list<string>
     */
    private static function namesOf(array $tokens, string $shortName): array
    {
        $names = [$shortName];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]->id !== \T_USE) {
                continue;
            }
            // The statement without its whitespace: `<name> as <Alias>` is then three tokens in a row.
            $statement = [];
            for ($i++; $i < $count && $tokens[$i]->text !== ';'; $i++) {
                if (!$tokens[$i]->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                    $statement[] = $tokens[$i];
                }
            }
            for ($k = 0; $k + 2 < count($statement); $k++) {
                if (self::names($statement[$k], [$shortName]) && $statement[$k + 1]->id === \T_AS && $statement[$k + 2]->id === \T_STRING) {
                    $names[] = $statement[$k + 2]->text;
                }
            }
        }

        return $names;
    }

    /**
     * @param list<string> $shortNames the short name first, then its import aliases
     */
    private static function names(\PhpToken $token, array $shortNames): bool
    {
        if ($token->id === \T_STRING) {
            return in_array($token->text, $shortNames, true);
        }
        if (!$token->is([\T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
            return false;
        }

        // An import never applies to a qualified name: `\Vendor\Queue\Job` is not
        // the alias `Job`. Only the short name itself is matched by its last segment.
        return substr($token->text, (int) strrpos($token->text, '\\') + 1) === ($shortNames[0] ?? null);
    }
}
