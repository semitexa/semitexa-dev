<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        foreach (['fix_bug', 'bugfix', 'debug_investigate'] as $recipe) {
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

    private const EVIDENCE = 'bin/semitexa ai:verify --json => "verdict":"fail"';

    #[DataProvider('bugRecipes')]
    public function test_in_the_stabilization_mode_a_bug_is_approved_only_as_a_blocker_with_evidence(string $recipe): void
    {
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, $recipe, self::NOW, true));
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, $recipe, self::NOW, true, 'security'));
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, $recipe, self::NOW, true, null, self::EVIDENCE));
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, $recipe, self::NOW, true, 'annoying', self::EVIDENCE));
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, $recipe, self::NOW, true, 'security', 'it looked wrong'));

        $approval = TaskFiling::approvalOnFiling(Phase::WORK, $recipe, self::NOW, true, 'verify-failure', self::EVIDENCE);

        self::assertSame('rule', $approval?->by);
        self::assertSame('blocker verify-failure: ' . self::EVIDENCE, $approval->words);
    }

    #[DataProvider('debtPhases')]
    public function test_in_the_stabilization_mode_a_right_or_fast_ticket_is_an_idea_unless_a_blocker(Phase $phase): void
    {
        self::assertNull(TaskFiling::approvalOnFiling($phase, '', self::NOW, true));
        self::assertSame('rule', TaskFiling::approvalOnFiling($phase, '', self::NOW, true, 'bench-budget', self::EVIDENCE)?->by);
    }

    public function test_in_the_stabilization_mode_a_blocker_class_alone_approves_no_new_work(): void
    {
        self::assertNull(TaskFiling::approvalOnFiling(Phase::WORK, 'add_feature', self::NOW, true, 'crash', self::EVIDENCE));
    }

    public function test_outside_the_mode_a_blocker_class_changes_nothing(): void
    {
        self::assertEquals(TaskFiling::approvalOnFiling(Phase::WORK, 'fix_bug', self::NOW), TaskFiling::approvalOnFiling(Phase::WORK, 'fix_bug', self::NOW, false, 'crash', self::EVIDENCE));
        self::assertNull(TaskFiling::refusal('fix_bug', false, null, null));
    }

    public function test_the_refusal_says_how_to_file_it_instead(): void
    {
        $refusal = TaskFiling::refusal('fix_bug', true, null, null);

        self::assertNotNull($refusal);
        self::assertStringContainsString('--blocker=<crash|leak|security|verify-failure|docs-contradicted|bench-budget>', $refusal);
        self::assertStringContainsString('file it without --recipe', $refusal);
        self::assertNull(TaskFiling::refusal('fix_bug', true, 'leak', self::EVIDENCE));
        self::assertNull(TaskFiling::refusal('', true, null, self::EVIDENCE), 'an idea may carry its evidence');
        self::assertNotNull(TaskFiling::refusal('', false, 'annoying', self::EVIDENCE));
        self::assertNotNull(TaskFiling::refusal('', false, 'leak', null));
        self::assertNotNull(TaskFiling::refusal('', false, null, 'no arrow here'));
    }

    private function filed(string $recipe, ?Phase $phase, ?TaskApproval $approval): Task
    {
        return new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, $recipe, 'low', [], null, 'tk-a', self::NOW, self::NOW, $approval, $phase);
    }

    public function test_the_blocker_class_is_read_back_from_the_task(): void
    {
        self::assertSame('leak', TaskFiling::blockerOf($this->filed('fix_bug', Phase::WORK, TaskFiling::approvalOnFiling(Phase::WORK, 'fix_bug', self::NOW, true, 'leak', self::EVIDENCE))));
        self::assertNull(TaskFiling::blockerOf($this->filed('fix_bug', Phase::WORK, TaskFiling::approvalOnFiling(Phase::WORK, 'fix_bug', self::NOW))));
        self::assertNull(TaskFiling::blockerOf($this->filed('fix_bug', Phase::WORK, new TaskApproval('owner', 'blocker leak: so they said', self::NOW))), 'the owner\'s words are not the rule\'s');
    }

    public function test_in_the_mode_a_refiled_ticket_keeps_only_a_blockers_approval(): void
    {
        $before = $this->filed('', Phase::FAST, TaskFiling::approvalOnFiling(Phase::RIGHT, '', self::NOW));
        $blocker = $this->filed('', Phase::FAST, TaskFiling::approvalOnFiling(Phase::RIGHT, '', self::NOW, true, 'bench-budget', self::EVIDENCE));
        $owners = $this->filed('', Phase::FAST, new TaskApproval('owner', 'Так', self::NOW));

        self::assertFalse(TaskFiling::afterEdit($before, self::NOW, true)->isApproved());
        self::assertTrue(TaskFiling::afterEdit($blocker, self::NOW, true)->isApproved());
        self::assertTrue(TaskFiling::afterEdit($owners, self::NOW, true)->isApproved());
        self::assertTrue(TaskFiling::afterEdit($before, self::NOW)->isApproved(), 'outside the mode an edit changes nothing');
    }

    public function test_in_the_mode_an_edit_does_not_make_an_idea_a_bug(): void
    {
        $idea = $this->filed('', null, null);

        self::assertNotNull(TaskFiling::refusalOfEdit($idea, 'fix_bug', true));
        self::assertNull(TaskFiling::refusalOfEdit($idea, 'fix_bug', false));
        self::assertNull(TaskFiling::refusalOfEdit($idea, 'add_feature', true));
        self::assertNull(TaskFiling::refusalOfEdit($this->filed('', null, new TaskApproval('owner', 'Так', self::NOW)), 'fix_bug', true));
    }
}
