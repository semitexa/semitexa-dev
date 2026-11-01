<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Charter;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Charter\Charter;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterInterview;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;

/**
 * Semitexa decides what to ask the owner next; the interview can stop at any
 * answer and resume at the first question still open.
 */
final class CharterInterviewTest extends TestCase
{
    #[Test]
    public function a_new_project_is_asked_its_goal_first_and_every_question_in_order(): void
    {
        self::assertSame(['goal', 'audience', 'problem', 'signs', 'constraints', 'non_goals', 'owner_decides'], CharterInterview::fields());
        self::assertSame('goal', CharterInterview::next(new Charter())?->field);
        self::assertCount(7, CharterInterview::open(new Charter()));
    }

    #[Test]
    public function the_next_question_is_the_first_one_without_an_answer(): void
    {
        $charter = new Charter(goal: 'A shop', audience: 'Local bakers');

        self::assertSame('problem', CharterInterview::next($charter)?->field);
        // An answer further on does not skip the open one before it.
        self::assertSame('problem', CharterInterview::next($charter->with(nonGoals: []))?->field);
    }

    #[Test]
    public function none_is_an_answer_but_a_blank_text_or_no_sign_is_not(): void
    {
        $charter = new Charter(goal: '  ', audience: 'a', problem: 'p', constraints: [], nonGoals: [], ownerDecides: []);
        self::assertSame('goal', CharterInterview::next($charter)?->field, 'a blank goal is unanswered');

        $charter = $charter->with(goal: 'g');
        self::assertSame('signs', CharterInterview::next($charter)?->field, 'a project needs a sign of success');

        $complete = $charter->with(signs: [new SuccessSign('s1', 'ten orders a day')]);
        self::assertNull(CharterInterview::next($complete));
        self::assertSame([], CharterInterview::open($complete));
    }

    #[Test]
    public function a_charter_round_trips_and_a_broken_one_is_refused(): void
    {
        $charter = new Charter('g', 'a', 'p', [new SuccessSign('s1', 'p99 under 200 ms', 'perf.p99-ms'), new SuccessSign('s2', 'a baker can publish a menu')], ['EU hosting'], [], Charter::DEFAULT_OWNER_DECIDES, '2026-11-01T10:00:00+00:00');
        $array = $charter->toArray();

        self::assertSame([
            ['id' => 's1', 'text' => 'p99 under 200 ms', 'kind' => 'computed', 'source' => 'perf.p99-ms'],
            ['id' => 's2', 'text' => 'a baker can publish a menu', 'kind' => 'judged', 'source' => null],
        ], $array['signs']);
        self::assertEquals($charter, Charter::fromArray($array));

        foreach ([['schema_version' => Charter::SCHEMA_VERSION, 'signs' => null], ['schema_version' => Charter::SCHEMA_VERSION, 'signs' => [], 'constraints' => ['EU', ' ']], [], ['schema_version' => 'other/v1'], ['schema_version' => Charter::SCHEMA_VERSION, 'goal' => 3], ['schema_version' => Charter::SCHEMA_VERSION, 'constraints' => 'EU'], ['schema_version' => Charter::SCHEMA_VERSION, 'signs' => [['id' => 's1', 'text' => ' ']]]] as $broken) {
            try {
                Charter::fromArray($broken);
                self::fail('accepted ' . json_encode($broken));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
