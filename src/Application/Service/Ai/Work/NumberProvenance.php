<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Whether a backlog note states a count without the command that produced it.
 *
 * A figure written into a next-step is measured once and then rots while the
 * code moves, and nothing in the text says it is a snapshot: three times in
 * one session a recorded count was wrong (47 call sites that were 86, 18
 * stores that were 9) and each cost a plan. The rule (AGENTS.md §3) is that a
 * number carries its command, so the next reader re-measures in seconds.
 *
 * A heuristic on purpose, and only ever a warning: a count is an integer
 * standing on its own followed by a word ("86 call sites", "37 errors"); a
 * command is a code span or a recognisable invocation anywhere in the note.
 * Dates, durations, versions, ids and hashes never count — they are not
 * measurements.
 */
final class NumberProvenance
{
    public const WARNING = 'number-without-command';

    // A duration ("12 months", "5 min") is a setting or a deadline, not a measurement.
    private const COUNT = '/(?<![\w.:\/#-])\d+(?![\w.:\/%-])\s+(?!(?:ms|sec|secs|seconds?|min|mins|minutes?|hours?|days?|weeks?|months?|years?)\b)[A-Za-z]{3,}/i';

    private const COMMAND = '/`|\b(?:bin\/semitexa|vendor\/bin\/|docker|grep|rg|git|wc|find|php|phpstan|phpunit|curl|python3?|npx|sha256sum)\b/';

    public static function bare(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        return preg_match(self::COUNT, $text) === 1 && preg_match(self::COMMAND, $text) !== 1;
    }
}
