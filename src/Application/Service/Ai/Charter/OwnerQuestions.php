<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

/**
 * What ai:orient tells a cold-start agent about the owner's interview: the
 * next question Semitexa asks, how many are open, how to ask, and a broken
 * charter said as such rather than shown as "nothing to ask".
 */
final class OwnerQuestions
{
    /**
     * @return array{next: array{field: string, question: string, why: string, record: string}|null, open: int, how_to_ask: ?string, error: ?string}
     */
    public static function of(CharterStore $store): array
    {
        try {
            $charter = $store->get();
        } catch (\RuntimeException $e) {
            return ['next' => null, 'open' => 0, 'how_to_ask' => null, 'error' => $e->getMessage()];
        }
        $next = CharterInterview::next($charter);

        return [
            'next' => $next?->toArray(),
            'open' => count(CharterInterview::open($charter)),
            'how_to_ask' => $next === null ? null : CharterInterview::HOW_TO_ASK,
            'error' => null,
        ];
    }

    /**
     * The orient hints with the owner's question leading: the project's
     * direction is what the rest of the work is for.
     *
     * @param array{summary: string, commands: list<array{cmd: string, args: list<string>, why: string}>} $hints
     * @param array{next: array{field: string, question: string, why: string, record: string}|null, open: int, how_to_ask: ?string, error: ?string} $questions
     * @return array{summary: string, commands: list<array{cmd: string, args: list<string>, why: string}>}
     */
    public static function lead(array $hints, array $questions): array
    {
        if ($questions['error'] !== null) {
            // Said where every reader of orient looks, human or JSON.
            return ['summary' => 'The project charter cannot be read (' . $questions['error'] . '): fix or remove it before asking the owner anything. ' . $hints['summary'], 'commands' => $hints['commands']];
        }
        $next = $questions['next'];
        if ($next === null) {
            return $hints;
        }

        $commands = [[
            'cmd' => 'ai:charter',
            'args' => ['answer', '--field=' . $next['field'], '--value="<the owner\'s answer>"', '--json'],
            'why' => 'record the owner\'s answer; ai:charter next gives the question after it',
        ]];
        // A list field's answer may be "none", and what the owner decides may
        // be the defaults: each gets the command that records it.
        $other = match ($next['field']) {
            'constraints', 'non_goals', 'owner_decides' => ['--none', 'when the owner\'s answer is "none"'],
            default => null,
        };
        if ($next['field'] === 'owner_decides') {
            $other = ['--keep', 'when the owner keeps the defaults'];
        }
        if ($other !== null) {
            $commands[] = ['cmd' => 'ai:charter', 'args' => ['answer', '--field=' . $next['field'], $other[0], '--json'], 'why' => $other[1]];
        }

        return [
            'summary' => 'Semitexa has a question for the project\'s owner: "' . $next['question'] . '" ' . $hints['summary'],
            'commands' => [...$commands, ...$hints['commands']],
        ];
    }
}
