<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\MakeItPlan;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

final class MakeItPlanTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    /** @param list<string> $refs */
    private function task(
        string $id,
        ?Phase $phase = Phase::WORK,
        TaskStatus $status = TaskStatus::NEW,
        bool $approved = true,
        string $recipe = '',
        string $created = self::NOW,
        array $refs = [],
    ): Task {
        return new Task($id, 'ep-a', 't', $status, $recipe, 'low', $refs, null, $id, $created, $created, $approved ? new TaskApproval('owner', 'Так', self::NOW) : null, $phase);
    }

    /**
     * @param list<Task> $tasks
     * @param list<string> $held
     */
    private function plan(array $tasks, array $held = [], ?string $mine = null, bool $withFramework = false): MakeItPlan
    {
        return MakeItPlan::of($tasks, $withFramework, static fn (string $id): bool => in_array($id, $held, true), $mine);
    }

    public function test_an_empty_backlog_asks_for_the_interview_and_stops_the_loop(): void
    {
        $plan = $this->plan([]);

        self::assertSame(MakeItPlan::EMPTY, $plan->state);
        self::assertFalse($plan->goOn());
        self::assertStringContainsString('what must work first', $plan->steps('x')[0]);
        self::assertStringContainsString('question under interview', MakeItPlan::of([], false, static fn (): bool => false, null, 7)->steps('x')[0]);
    }

    public function test_a_backlog_of_discarded_tasks_is_empty(): void
    {
        self::assertSame(MakeItPlan::EMPTY, $this->plan([$this->task('tk-a', status: TaskStatus::DISCARDED)])->state);
    }

    public function test_filing_an_idea_does_not_end_the_interview(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-idea', approved: false)], false, static fn (): bool => false, null, 6);

        self::assertSame(MakeItPlan::EMPTY, $plan->state);
        self::assertStringContainsString('6 questions are open', $plan->say());
        self::assertStringContainsString('resumes where it stopped', $plan->steps('x')[0]);
    }

    public function test_a_closed_legacy_bug_does_not_end_the_interview(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-bug', status: TaskStatus::DONE, approved: false, recipe: 'fix_bug'), $this->task('tk-idea', approved: false)], false, static fn (): bool => false, null, 6);

        self::assertSame(MakeItPlan::EMPTY, $plan->state);
    }

    public function test_with_the_charter_complete_ideas_are_offered(): void
    {
        self::assertSame(MakeItPlan::IDEAS, MakeItPlan::of([$this->task('tk-idea', approved: false)], false, static fn (): bool => false, null, 0)->state);
    }

    public function test_approved_work_goes_on_with_the_charter_unfinished(): void
    {
        self::assertSame(MakeItPlan::WORK, MakeItPlan::of([$this->task('tk-a')], false, static fn (): bool => false, null, 6)->state);
    }

    public function test_finished_work_is_done_with_the_charter_unfinished(): void
    {
        self::assertSame(MakeItPlan::DONE, MakeItPlan::of([$this->task('tk-a', status: TaskStatus::DONE)], false, static fn (): bool => false, null, 6)->state);
    }

    public function test_a_phase_narrows_what_is_taken(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-bug', recipe: 'fix_bug'), $this->task('tk-fast', Phase::FAST)], false, static fn (): bool => false, null, only: Phase::FAST);

        self::assertSame('tk-fast', $plan->tasks[0]['id']);
    }

    public function test_with_nothing_under_the_phase_it_says_so_and_no_more(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-work'), $this->task('tk-idea', Phase::RIGHT, approved: false)], false, static fn (): bool => false, null, 3, only: Phase::RIGHT);

        self::assertSame(MakeItPlan::DONE, $plan->state);
        self::assertSame('Nothing approved is open under right.', $plan->say());
        self::assertFalse($plan->goOn());
    }

    public function test_a_phase_does_not_skip_an_open_interview(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-idea', Phase::RIGHT, approved: false)], false, static fn (): bool => false, null, 6, only: Phase::RIGHT);

        self::assertSame(MakeItPlan::EMPTY, $plan->state);
    }

    public function test_what_this_session_holds_stays_first_under_another_phase(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-fast', Phase::FAST), $this->task('tk-mine', Phase::WORK, TaskStatus::IN_PROGRESS)], false, static fn (): bool => false, 'tk-mine', only: Phase::FAST);

        self::assertSame('tk-mine', $plan->tasks[0]['id']);
    }

    public function test_the_task_named_says_where_it_lives(): void
    {
        $plan = $this->plan([$this->task('tk-a', refs: ['packages/semitexa-orm/src/X.php'])], withFramework: true);

        self::assertSame(['packages/semitexa-orm/src/X.php'], $plan->tasks[0]['context_refs']);
    }

    public function test_a_phase_with_what_is_open_blocked_waits(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-a', Phase::RIGHT, TaskStatus::BLOCKED)], false, static fn (): bool => false, null, only: Phase::RIGHT);

        self::assertSame(MakeItPlan::WAITING, $plan->state);
    }

    public function test_closed_work_is_done_whatever_the_charter_lacks(): void
    {
        self::assertSame(MakeItPlan::DONE, MakeItPlan::of([$this->task('tk-a', status: TaskStatus::DONE, approved: false)], false, static fn (): bool => false, null, 6)->state);
    }

    public function test_a_broken_charter_comes_before_ideas(): void
    {
        $plan = MakeItPlan::of([$this->task('tk-idea', approved: false)], false, static fn (): bool => false, null, 0, 'bad json');

        self::assertSame(MakeItPlan::EMPTY, $plan->state);
        self::assertStringContainsString('bad json', $plan->steps('x')[0]);
    }

    public function test_an_approved_task_is_taken_and_the_loop_goes_on(): void
    {
        $plan = $this->plan([$this->task('tk-a')]);

        self::assertSame(MakeItPlan::WORK, $plan->state);
        self::assertTrue($plan->goOn());
        self::assertSame('tk-a', $plan->tasks[0]['id']);
    }

    public function test_the_order_is_bugs_work_right_fast_then_no_phase(): void
    {
        $tasks = [
            $this->task('tk-none', phase: null),
            $this->task('tk-fast', Phase::FAST),
            $this->task('tk-right', Phase::RIGHT),
            $this->task('tk-work'),
            $this->task('tk-bug', phase: null, approved: false, recipe: 'fix_bug'),
        ];

        foreach (['tk-bug', 'tk-work', 'tk-right', 'tk-fast', 'tk-none'] as $expected) {
            $plan = $this->plan($tasks);
            self::assertSame($expected, $plan->tasks[0]['id']);
            $tasks = array_values(array_filter($tasks, static fn (Task $t): bool => $t->id !== $expected));
        }
    }

    public function test_a_bug_filed_under_fast_still_comes_first(): void
    {
        self::assertSame('tk-bug', $this->plan([$this->task('tk-work'), $this->task('tk-bug', Phase::FAST, recipe: 'fix_bug')])->tasks[0]['id']);
    }

    public function test_the_oldest_goes_first_within_a_rank(): void
    {
        $plan = $this->plan([$this->task('tk-new', created: '2026-11-06T02:00:00+00:00'), $this->task('tk-old', created: '2026-11-06T01:00:00+00:00')]);

        self::assertSame('tk-old', $plan->tasks[0]['id']);
    }

    public function test_what_this_session_holds_comes_before_a_bug(): void
    {
        $plan = $this->plan([$this->task('tk-bug', recipe: 'fix_bug'), $this->task('tk-mine', Phase::FAST, TaskStatus::IN_PROGRESS)], mine: 'tk-mine');

        self::assertSame('tk-mine', $plan->tasks[0]['id']);
    }

    public function test_blocked_and_held_tasks_are_passed_over(): void
    {
        $plan = $this->plan([$this->task('tk-blocked', status: TaskStatus::BLOCKED), $this->task('tk-held', status: TaskStatus::IN_PROGRESS), $this->task('tk-free', Phase::FAST)], ['tk-held']);

        self::assertSame('tk-free', $plan->tasks[0]['id']);
    }

    public function test_when_all_that_is_open_waits_the_loop_stops_and_names_it(): void
    {
        $plan = $this->plan([$this->task('tk-blocked', status: TaskStatus::BLOCKED), $this->task('tk-held', status: TaskStatus::IN_PROGRESS), $this->task('tk-idea', approved: false)], ['tk-held']);

        self::assertSame(MakeItPlan::WAITING, $plan->state);
        self::assertFalse($plan->goOn());
        self::assertSame(2, $plan->total);
    }

    public function test_ideas_are_offered_never_taken(): void
    {
        $plan = $this->plan([$this->task('tk-closed', status: TaskStatus::DONE), $this->task('tk-idea', approved: false)]);

        self::assertSame(MakeItPlan::IDEAS, $plan->state);
        self::assertFalse($plan->goOn());
        self::assertSame('tk-idea', $plan->tasks[0]['id']);
    }

    public function test_a_closed_legacy_bug_is_no_approval(): void
    {
        self::assertStringStartsWith('Nothing is approved yet.', $this->plan([$this->task('tk-bug', status: TaskStatus::DONE, approved: false, recipe: 'fix_bug'), $this->task('tk-idea', approved: false)])->say());
    }

    public function test_a_first_cut_is_not_called_closed(): void
    {
        self::assertStringStartsWith('Nothing is approved yet.', $this->plan([$this->task('tk-idea', approved: false)])->say());
        self::assertStringStartsWith('Everything approved is closed.', $this->plan([$this->task('tk-a', status: TaskStatus::DONE), $this->task('tk-idea', approved: false)])->say());
    }

    public function test_the_steps_ask_to_look_at_a_page(): void
    {
        self::assertStringContainsString('look at it', implode("\n", $this->plan([$this->task('tk-a')])->steps('x')));
    }

    public function test_the_backlog_is_committed_with_the_work(): void
    {
        self::assertStringContainsString('then commit var/ai-work', implode("\n", $this->plan([$this->task('tk-a')])->steps('x')));
        $commit = array_values(array_filter($this->plan([$this->task('tk-a')])->steps('x'), static fn (string $s): bool => str_starts_with($s, 'Commit this task alone')));
        self::assertCount(1, $commit);
        self::assertStringNotContainsString('var/ai-work', $commit[0], 'the backlog is committed after the close, in its final state');
        self::assertStringContainsString('Commit var/ai-work', implode("\n", $this->plan([$this->task('tk-a', status: TaskStatus::BLOCKED)])->steps('x')));
        self::assertStringContainsString('Commit var/ai-work', implode("\n", $this->plan([$this->task('tk-idea', approved: false)])->steps('x')));
        self::assertStringContainsString('Commit var/ai-work', implode("\n", $this->plan([$this->task('tk-a', status: TaskStatus::DONE)])->steps('x')));
    }

    public function test_a_long_list_of_ideas_is_cut_but_counted(): void
    {
        $ideas = array_map(fn (int $i): Task => $this->task("tk-{$i}", approved: false), range(1, 25));

        $plan = $this->plan($ideas);

        self::assertCount(10, $plan->tasks);
        self::assertSame(25, $plan->total);
    }

    public function test_everything_closed_is_done_and_asks_for_new_ideas(): void
    {
        $plan = $this->plan([$this->task('tk-a', status: TaskStatus::DONE), $this->task('tk-b', status: TaskStatus::DISCARDED)]);

        self::assertSame(MakeItPlan::DONE, $plan->state);
        self::assertFalse($plan->goOn());
        self::assertStringContainsString('new ideas', $plan->say());
    }

    public function test_closed_work_nobody_approved_is_not_an_idea(): void
    {
        self::assertSame(MakeItPlan::DONE, $this->plan([$this->task('tk-a', status: TaskStatus::DONE, approved: false)])->state);
    }

    public function test_framework_tasks_are_left_out_without_the_option(): void
    {
        $tasks = [$this->task('tk-fw', refs: ['packages/semitexa-orm/src/X.php'])];

        self::assertSame(MakeItPlan::EMPTY, $this->plan($tasks)->state);
        self::assertSame(MakeItPlan::WORK, $this->plan($tasks, withFramework: true)->state);
    }

    public function test_the_steps_name_the_task_the_commit_line_and_the_way_back(): void
    {
        $steps = implode("\n", $this->plan([$this->task('tk-a')])->steps('run-me'));

        self::assertStringContainsString('--id=tk-a --status=in_progress', $steps);
        self::assertStringContainsString('Task: tk-a', $steps);
        self::assertStringContainsString('--status=blocked', $steps);
        self::assertStringContainsString('Run run-me again', $steps);
    }
}
