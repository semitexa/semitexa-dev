<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\FrameworkEvidence;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskFiling;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

final class TaskFilingTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    public function test_new_work_is_an_idea(): void
    {
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, 'add_feature', self::NOW));
        self::assertNull(TaskFiling::approvalOnFiling(null, '', self::NOW));
    }

    /** @return iterable<string, array{Phase}> */
    public static function debtPhases(): iterable
    {
        yield 'right' => [Phase::RIGHT];
        yield 'fast' => [Phase::FAST];
    }

    #[DataProvider('debtPhases')]
    public function test_right_and_fast_count_from_filing(Phase $phase): void
    {
        self::assertSame('rule', TaskFiling::approvalOnFiling($phase, '', self::NOW)?->by);
    }

    /** @return iterable<string, array{string}> */
    public static function bugRecipes(): iterable
    {
        foreach (FrameworkEvidence::BUG_RECIPES as $recipe) {
            yield $recipe => [$recipe];
        }
    }

    #[DataProvider('bugRecipes')]
    public function test_a_bug_is_work_and_counts_from_filing(string $recipe): void
    {
        $phase = TaskFiling::phaseOf(null, $recipe);

        self::assertSame(Phase::WORK, $phase);
        self::assertSame('rule', TaskFiling::approvalOnFiling($phase, $recipe, self::NOW)?->by);
    }

    public function test_a_bug_keeps_the_phase_it_was_given(): void
    {
        self::assertSame(Phase::FAST, TaskFiling::phaseOf(Phase::FAST, 'fix_bug'));
    }

    public function test_no_phase_and_no_bug_stays_without_a_phase(): void
    {
        self::assertNull(TaskFiling::phaseOf(null, 'add_feature'));
    }

    public function test_parse_reads_a_phase_and_refuses_anything_else(): void
    {
        self::assertSame(Phase::RIGHT, TaskFiling::parsePhase(' Right '));
        self::assertNull(TaskFiling::parsePhase(null));

        $this->expectException(\InvalidArgumentException::class);
        TaskFiling::parsePhase('later');
    }

    public function test_a_stored_phase_survives_a_round_trip_and_later_changes(): void
    {
        $task = Task::fromArray((new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, '', 'low', [], null, 'tk-a', self::NOW, self::NOW, null, Phase::FAST))->toArray());

        self::assertSame(Phase::FAST, $task->phase);
        self::assertSame(Phase::FAST, $task->with(status: TaskStatus::DONE)->phase);
        self::assertSame(Phase::FAST, $task->withApproval(new TaskApproval('owner', 'Так', self::NOW), self::NOW)->phase);
    }

    public function test_a_task_with_no_or_an_unknown_phase_reads_as_none(): void
    {
        self::assertNull(Task::fromArray(['id' => 'tk-old'])->phase);
        self::assertNull(Task::fromArray(['id' => 'tk-a', 'phase' => 'later'])->phase);
    }
}
