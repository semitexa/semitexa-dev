<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\BacklogProgress;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

final class BacklogProgressTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    /** @param list<string> $refs */
    private function task(
        TaskStatus $status = TaskStatus::NEW,
        ?Phase $phase = Phase::WORK,
        bool $approved = true,
        string $recipe = '',
        array $refs = [],
        bool $proven = true,
    ): Task {
        $proof = $proven && $status === TaskStatus::DONE ? new TaskProof('rcpt-1', self::NOW, ['phpunit:X'], []) : null;

        return new Task('tk-a', 'ep-a', 't', $status, $recipe, 'low', $refs, null, 'tk-a', self::NOW, self::NOW, $approved ? new TaskApproval('owner', 'Так', self::NOW) : null, $phase, $proof);
    }

    public function test_done_with_no_proof_is_shown_and_adds_nothing(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DONE), $this->task(TaskStatus::DONE, proven: false), $this->task()], false);

        self::assertSame(['percent' => 33, 'done' => 1, 'approved' => 3, 'open' => 1, 'unproven' => 1], $progress->phases['work']);
        self::assertStringStartsWith('work 33% (1/3, 1 open, 1 unproven) · ', $progress->line());
    }

    public function test_a_hundred_needs_every_task_proven(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DONE), $this->task(TaskStatus::DONE, proven: false)], false);

        self::assertSame(50, $progress->phases['work']['percent']);
    }

    public function test_an_empty_backlog_is_not_measured_never_zero_or_a_hundred(): void
    {
        $progress = BacklogProgress::of([], false);

        self::assertNull($progress->phases['work']['percent']);
        self::assertSame('work not measured · right not measured · fast not measured', $progress->line());
    }

    public function test_a_phase_reads_done_over_approved_with_the_count_beside_it(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DONE), $this->task(), $this->task(TaskStatus::IN_PROGRESS), $this->task(TaskStatus::BLOCKED)], false);

        self::assertSame(['percent' => 25, 'done' => 1, 'approved' => 4, 'open' => 3, 'unproven' => 0], $progress->phases['work']);
        self::assertStringStartsWith('work 25% (1/4, 3 open) · ', $progress->line());
    }

    public function test_a_found_bug_moves_the_percent(): void
    {
        $done = $this->task(TaskStatus::DONE);

        self::assertSame(100, BacklogProgress::of([$done], false)->phases['work']['percent']);
        self::assertSame(50, BacklogProgress::of([$done, $this->task(recipe: 'fix_bug')], false)->phases['work']['percent']);
    }

    public function test_a_hundred_is_said_only_when_nothing_is_open(): void
    {
        $tasks = array_fill(0, 999, $this->task(TaskStatus::DONE));
        $tasks[] = $this->task();

        self::assertSame(99, BacklogProgress::of($tasks, false)->phases['work']['percent']);
    }

    public function test_an_idea_is_counted_apart_and_never_in_a_percent(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DONE), $this->task(approved: false), $this->task(phase: null, approved: false)], false);

        self::assertSame(100, $progress->phases['work']['percent']);
        self::assertSame(2, $progress->ideas);
        self::assertStringEndsWith('· 2 ideas', $progress->line());
    }

    public function test_closed_work_nobody_approved_is_neither_an_idea_nor_counted(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DONE, phase: null, approved: false)], false);

        self::assertSame(0, $progress->ideas);
        self::assertNull($progress->phases['work']['percent']);
    }

    public function test_a_discarded_task_counts_nowhere(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DISCARDED), $this->task(TaskStatus::DISCARDED, approved: false)], false);

        self::assertSame(0, $progress->phases['work']['approved']);
        self::assertSame(0, $progress->ideas);
    }

    public function test_a_bug_from_before_approval_existed_is_counted_as_work(): void
    {
        $progress = BacklogProgress::of([$this->task(phase: null, approved: false, recipe: 'fix_bug')], false);

        self::assertSame(1, $progress->phases['work']['open']);
        self::assertSame(0, $progress->ideas);
    }

    public function test_an_approved_task_with_no_phase_is_shown_not_lost(): void
    {
        $progress = BacklogProgress::of([$this->task(phase: null)], false);

        self::assertSame(1, $progress->noPhase);
        self::assertStringEndsWith('· 1 approved with no phase', $progress->line());
    }

    public function test_each_phase_counts_on_its_own(): void
    {
        $progress = BacklogProgress::of([$this->task(TaskStatus::DONE, Phase::RIGHT), $this->task(phase: Phase::FAST)], false);

        self::assertSame(100, $progress->phases['right']['percent']);
        self::assertSame(0, $progress->phases['fast']['percent']);
        self::assertNull($progress->phases['work']['percent']);
    }

    public function test_framework_tasks_count_only_with_the_option(): void
    {
        $tasks = [$this->task(refs: ['packages/semitexa-orm/src/X.php']), $this->task(refs: ['vendor/semitexa/core/src/Y.php']), $this->task(refs: ['Semitexa\\Core\\Z'])];

        self::assertSame(0, BacklogProgress::of($tasks, false)->phases['work']['approved']);
        self::assertSame(3, BacklogProgress::of($tasks, true)->phases['work']['approved']);
    }

    public function test_a_leading_dot_slash_changes_nothing(): void
    {
        self::assertSame(0, BacklogProgress::of([$this->task(refs: ['./packages/semitexa-orm/src/X.php'])], false)->phases['work']['approved']);
        self::assertSame(1, BacklogProgress::of([$this->task(refs: ['./packages/semitexa-orm/src/X.php', './src/modules/Shop/Y.php'])], false)->phases['work']['approved']);
    }

    public function test_a_task_touching_src_or_pointing_at_nothing_is_the_projects(): void
    {
        $tasks = [$this->task(refs: ['packages/semitexa-orm/src/X.php', 'src/modules/Shop/Y.php']), $this->task(refs: ['src/modules/Shop/Z.php']), $this->task(), $this->task(refs: ['Semitexa\\Modules\\Shop\\X'])];

        self::assertSame(4, BacklogProgress::of($tasks, false)->phases['work']['approved']);
    }
}
