<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Orient;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Orient\OrientBrief;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * The first call of a cold start answers in a few kilobytes
 * (tk-orient-cold-start-too-big). On 2026-11-12 the workspace's ai:orient was
 * 22.8 KB, read whole before any work: eight tasks with their whole next step,
 * nineteen unread red receipts, five traces.
 */
final class OrientBriefTest extends TestCase
{
    private const NOW = 1_762_934_400; // 2025-11-12T08:00:00Z

    #[Test]
    public function a_workspace_the_size_of_ours_is_told_in_under_six_kilobytes(): void
    {
        $brief = OrientBrief::of($this->envelope(), 'tk-0', 'claude-me', self::NOW);
        $json = (string) json_encode($brief, JSON_UNESCAPED_SLASHES);

        self::assertJson($json, 'an encoding failure is not a small answer');
        self::assertLessThan(6000, strlen($json), 'the brief grew past the size a cold start should read');
        self::assertGreaterThan(20000, strlen((string) json_encode($this->envelope(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)), 'the fixture is as big as the real one was');
        self::assertCount(8, $brief['backlog']['in_progress_tasks'], 'every task is still named');
        self::assertSame(['count' => 19, 'this_session' => 4, 'other_sessions' => 15], array_slice($brief['unread_failed_verify'], 0, 3));
        self::assertCount(3, $brief['recent_traces']);
        self::assertCount(1, $brief['quality_next']);
    }

    #[Test]
    public function only_the_suggested_task_is_marked_and_an_old_one_is_stale(): void
    {
        $tasks = OrientBrief::of($this->envelope(), 'tk-0', 'claude-me', self::NOW)['backlog']['in_progress_tasks'];

        self::assertTrue($tasks[0]['suggested'] ?? false);
        self::assertArrayNotHasKey('suggested', $tasks[1]);
        $source = $this->envelope()['backlog']['in_progress_tasks'];
        foreach ($tasks as $i => $task) {
            self::assertSame(
                [$source[$i]['id'], $source[$i]['title'], $source[$i]['updated_at'], null],
                [$task['id'] ?? false, $task['title'] ?? false, $task['updated_at'] ?? false, array_key_exists('held_by', $task) ? $task['held_by'] : false],
                'each task keeps what names it',
            );
            self::assertArrayNotHasKey('next_step', $task, 'a next step is read from suggest_next, once');
        }
        self::assertArrayNotHasKey('stale', $tasks[0]);
        self::assertTrue($tasks[7]['stale'] ?? false, 'untouched for over 14 days');
    }

    #[Test]
    public function a_task_another_live_session_holds_is_said_before_the_suggestion(): void
    {
        self::assertStringContainsString('held by `codex-1`', OrientBrief::heldElsewhere('tk-0', 'codex-1', 'claude-me'));
        self::assertSame('', OrientBrief::heldElsewhere('tk-0', 'claude-me', 'claude-me'));
        self::assertSame('', OrientBrief::heldElsewhere('tk-0', null, 'claude-me'));
    }

    #[Test]
    public function a_done_epic_is_never_the_active_one(): void
    {
        $at = '2025-11-12T08:00:00+00:00';
        $task = static fn (string $id, string $epic, TaskStatus $s): Task => new Task($id, $epic, $id, $s, 'r', 'low', [], null, $id, $at, $at);
        $epics = [new Epic('ep-done', 'd', 'g', EpicStatus::DONE, $at, $at), new Epic('ep-live', 'l', 'g', EpicStatus::IN_PROGRESS, $at, $at)];

        self::assertSame('ep-live', OrientBrief::activeEpicId([$task('a', 'ep-done', TaskStatus::IN_PROGRESS)], [], $epics));
        self::assertSame('ep-open', OrientBrief::activeEpicId([$task('a', 'ep-done', TaskStatus::IN_PROGRESS), $task('b', 'ep-open', TaskStatus::IN_PROGRESS)], [], $epics));
        self::assertSame('ep-live', OrientBrief::activeEpicId([], [$task('c', 'ep-blocked', TaskStatus::BLOCKED)], $epics), 'an epic in progress before a blocked task');
    }

    /** @return array<string, mixed> */
    private function envelope(): array
    {
        $step = str_repeat('Run the measure, read the receipt, then change the one file it names. ', 6);
        $tasks = [];
        for ($i = 0; $i < 8; $i++) {
            $tasks[] = [
                'id' => 'tk-' . $i, 'epic_id' => 'ep-x', 'title' => str_repeat('A task title that says what is wrong ', 3),
                'status' => 'in_progress', 'recipe' => 'fix_bug', 'risk' => 'medium', 'next_step' => $step,
                'context_refs' => ['packages/semitexa-dev/src/Application/Console/Command/AiOrientCommand.php'],
                'updated_at' => gmdate('c', self::NOW - ($i === 7 ? 20 : 0) * 86400 - $i * 600), 'held_by' => null,
            ];
        }
        $receipts = [];
        for ($i = 0; $i < 19; $i++) {
            $receipts[] = ['id' => 'rcpt-' . $i, 'generated_at' => gmdate('c', self::NOW), 'verdict' => 'fail',
                'run_by' => ['agent_session' => $i < 4 ? 'claude-me' : 'codex-' . $i, 'trace' => 'tk-' . ($i % 8)], 'note' => str_repeat('x', 100)];
        }
        $traces = [];
        for ($i = 0; $i < 5; $i++) {
            $traces[] = ['id' => 'tk-' . $i, 'created_at' => gmdate('c', self::NOW), 'topic' => str_repeat('topic ', 10), 'recipe' => 'fix_bug',
                'last_event' => ['kind' => 'note', 'at' => gmdate('c', self::NOW), 'summary' => str_repeat('what happened ', 12)]];
        }

        return [
            'artifact' => 'semitexa.ai-orient/v1',
            'working_now' => ['you' => ['id' => 'claude-me'], 'agents' => [], 'activity' => []],
            'backlog' => ['total_epics' => 3, 'active_epic_id' => 'ep-x', 'in_progress_tasks' => $tasks, 'in_progress_count' => 8, 'blocked_tasks' => [$tasks[1]], 'blocked_count' => 1],
            'recent_traces' => $traces,
            'unread_failed_verify' => $receipts,
            'quality_next' => array_fill(0, 3, ['metric' => 'requests.query-shapes', 'why' => str_repeat('why ', 60)]),
            'suggest_next' => 'Resume task `tk-0`. Next step on record: ' . $step,
            'next_command' => [],
        ];
    }
}
