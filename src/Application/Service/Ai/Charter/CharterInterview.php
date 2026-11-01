<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

/**
 * The interview Semitexa holds with a project's owner, kept as data: the
 * questions in order, and which one comes next for a given charter. The agent
 * asks; Semitexa decides what to ask. An unanswered question is the next one,
 * so the interview can stop at any point and resume where it stopped.
 */
final class CharterInterview
{
    /** How an agent is to put the questions: the owner is a person, not a form. */
    public const HOW_TO_ASK = 'Ask the project\'s owner, in their language and your own words, one question at a time; '
        . 'record the answer with ai:charter answer before the next. At a natural point of the conversation: '
        . 'never instead of what the owner asked you to do.';

    /** @return list<CharterQuestion> */
    public static function questions(): array
    {
        return [
            new CharterQuestion(
                'goal',
                'What is this project for? In a sentence or two: what exists once it has succeeded?',
                'every epic and task is weighed against it; without it an agent optimises what is easy to count',
                'ai:charter answer --field=goal --value="<the goal in the owner\'s words>"',
            ),
            new CharterQuestion(
                'audience',
                'Who is it for: who will use it, and who pays for it if that is someone else?',
                'decides whose problem a feature solves, and whose workflow a change may break',
                'ai:charter answer --field=audience --value="<who>"',
            ),
            new CharterQuestion(
                'problem',
                'What problem does it solve for them that they have today, and how do they deal with it now?',
                'the work worth doing first is what removes that problem',
                'ai:charter answer --field=problem --value="<the problem>"',
            ),
            new CharterQuestion(
                'signs',
                'How will you know it works? Name one to three signs of success you could check: a number to reach, something a user can do, a date.',
                'they become what the project state measures; a sign with a source (a metric) is computed, one without is judged with a proof',
                'ai:charter answer --field=signs --value="<a sign>" [--source=<metric or check>] (once per sign)',
            ),
            new CharterQuestion(
                'constraints',
                'What must hold whatever happens: budget, hosting, deadlines, legal, technology you must or must not use? "None" is an answer.',
                'an agent works inside them and asks before going near one',
                'ai:charter answer --field=constraints --value="<one constraint>" (repeat --value), or --none',
            ),
            new CharterQuestion(
                'non_goals',
                'What is this project deliberately not doing, at least for now? "None" is an answer.',
                'keeps an agent from building what nobody asked for',
                'ai:charter answer --field=non_goals --value="<one non-goal>" (repeat --value), or --none',
            ),
            new CharterQuestion(
                'owner_decides',
                'By default the agent decides technical questions on its own and asks you about: '
                    . implode('; ', Charter::DEFAULT_OWNER_DECIDES)
                    . '. Keep that, or change what it must ask you about?',
                'the line between what an agent does alone and what waits for the owner\'s word',
                'ai:charter answer --field=owner_decides --keep; --value="<one more thing to ask about>" adds to the defaults (repeat --value); --replace with --value sets the owner\'s own list',
            ),
        ];
    }

    /** The first question the charter has no answer to, or null when it is complete. */
    public static function next(Charter $charter): ?CharterQuestion
    {
        foreach (self::questions() as $question) {
            if (!self::answered($charter, $question->field)) {
                return $question;
            }
        }

        return null;
    }

    /** @return list<CharterQuestion> the questions still open, in order */
    public static function open(Charter $charter): array
    {
        return array_values(array_filter(self::questions(), static fn (CharterQuestion $q): bool => !self::answered($charter, $q->field)));
    }

    public static function answered(Charter $charter, string $field): bool
    {
        return match ($field) {
            'goal' => self::filled($charter->goal),
            'audience' => self::filled($charter->audience),
            'problem' => self::filled($charter->problem),
            'signs' => $charter->signs !== [],
            'constraints' => $charter->constraints !== null,
            'non_goals' => $charter->nonGoals !== null,
            'owner_decides' => $charter->ownerDecides !== null,
            default => throw new \InvalidArgumentException("no charter question \"{$field}\""),
        };
    }

    /** @return list<string> */
    public static function fields(): array
    {
        return array_map(static fn (CharterQuestion $q): string => $q->field, self::questions());
    }

    private static function filled(?string $text): bool
    {
        return $text !== null && !Blank::is($text);
    }
}
