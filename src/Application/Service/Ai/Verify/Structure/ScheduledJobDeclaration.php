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
            $tokens = \PhpToken::tokenize($source);
        } catch (\Throwable) {
            return false;
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->id === \T_ATTRIBUTE) {
                // Up to the bracket that closes the attribute group.
                $depth = 1;
                for ($j = $i + 1; $j < $count && $depth > 0; $j++) {
                    $text = $tokens[$j]->text;
                    if ($text === '[' || $tokens[$j]->id === \T_ATTRIBUTE) {
                        $depth++;
                    } elseif ($text === ']') {
                        $depth--;
                    } elseif (self::names($tokens[$j], self::ATTRIBUTE)) {
                        return true;
                    }
                }
                continue;
            }

            if ($token->id === \T_IMPLEMENTS) {
                for ($j = $i + 1; $j < $count && $tokens[$j]->text !== '{'; $j++) {
                    if (self::names($tokens[$j], self::CONTRACT)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private static function names(\PhpToken $token, string $shortName): bool
    {
        if (!$token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
            return false;
        }
        $pos = strrpos($token->text, '\\');

        return ($pos === false ? $token->text : substr($token->text, $pos + 1)) === $shortName;
    }
}
