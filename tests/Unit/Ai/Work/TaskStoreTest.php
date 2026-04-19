<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Ai\Work\Task;
use Semitexa\Dev\Ai\Work\TaskStatus;
use Semitexa\Dev\Ai\Work\TaskStore;

class TaskStoreTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-task-' . uniqid();
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        $this->removeDir($this->root);
    }

    public function test_save_and_get_round_trip(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $task = new Task(
            id:          'tk-a',
            epicId:      'ep-a',
            title:       'title',
            status:      TaskStatus::IN_PROGRESS,
            recipe:      'add_authenticated_json_route',
            risk:        'medium',
            contextRefs: ['src/Foo.php', 'src/Bar.php'],
            nextStep:    'write tests',
            traceId:     'tr-a',
            createdAt:   $now,
            updatedAt:   $now,
        );
        $store->save($task);

        $loaded = $store->get('tk-a');
        $this->assertSame('tk-a', $loaded->id);
        $this->assertSame('ep-a', $loaded->epicId);
        $this->assertSame(TaskStatus::IN_PROGRESS, $loaded->status);
        $this->assertSame(['src/Foo.php', 'src/Bar.php'], $loaded->contextRefs);
        $this->assertSame('write tests', $loaded->nextStep);
        $this->assertSame('tr-a', $loaded->traceId);
    }

    public function test_list_filters_by_epic_and_status(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Task('tk-1', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-1', '2026-04-19T00:00:01+00:00', $now));
        $store->save(new Task('tk-2', 'ep-a', 't', TaskStatus::DONE, 'r', 'low', [], null, 'tk-2', '2026-04-19T00:00:02+00:00', $now));
        $store->save(new Task('tk-3', 'ep-b', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-3', '2026-04-19T00:00:03+00:00', $now));

        $inEpicA = array_map(static fn(Task $t) => $t->id, $store->list('ep-a'));
        $this->assertSame(['tk-1', 'tk-2'], $inEpicA);

        $newOnly = array_map(static fn(Task $t) => $t->id, $store->list(null, TaskStatus::NEW));
        $this->assertSame(['tk-1', 'tk-3'], $newOnly);

        $combined = array_map(static fn(Task $t) => $t->id, $store->list('ep-a', TaskStatus::DONE));
        $this->assertSame(['tk-2'], $combined);
    }

    public function test_list_sorts_by_created_at(): void
    {
        $store = new TaskStore();
        $store->save(new Task('tk-a', 'ep', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', '2026-04-19T00:00:03+00:00', '2026-04-19T00:00:03+00:00'));
        $store->save(new Task('tk-b', 'ep', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-b', '2026-04-19T00:00:01+00:00', '2026-04-19T00:00:01+00:00'));
        $store->save(new Task('tk-c', 'ep', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-c', '2026-04-19T00:00:02+00:00', '2026-04-19T00:00:02+00:00'));

        $ids = array_map(static fn(Task $t) => $t->id, $store->list());
        $this->assertSame(['tk-b', 'tk-c', 'tk-a'], $ids);
    }

    public function test_save_rejects_invalid_epic_id(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $task = new Task('tk-a', 'BAD EPIC', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now);
        $this->expectException(\InvalidArgumentException::class);
        $store->save($task);
    }

    public function test_save_rejects_missing_trace_id(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $task = new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, '', $now, $now);
        $this->expectException(\InvalidArgumentException::class);
        $store->save($task);
    }

    public function test_missing_task_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TaskStore())->get('tk-missing');
    }

    public function test_atomic_write_does_not_leave_tmp_files(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));

        $entries = scandir($this->root . '/var/ai-work/tasks');
        $this->assertNotFalse($entries);
        $tmps = array_filter($entries, static fn($e) => str_ends_with($e, '.tmp'));
        $this->assertSame([], array_values($tmps));
    }

    public function test_ignores_non_json_files_in_list(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));

        $dir = $this->root . '/var/ai-work/tasks';
        file_put_contents($dir . '/README.md', 'notes');

        $ids = array_map(static fn(Task $t) => $t->id, $store->list());
        $this->assertSame(['tk-a'], $ids);
    }

    public function test_with_preserves_id_and_epic(): void
    {
        $now = '2026-04-19T00:00:00+00:00';
        $task = new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now);
        $updated = $task->with(status: TaskStatus::IN_PROGRESS, nextStep: 'next');
        $this->assertSame('tk-a', $updated->id);
        $this->assertSame('ep-a', $updated->epicId);
        $this->assertSame(TaskStatus::IN_PROGRESS, $updated->status);
        $this->assertSame('next', $updated->nextStep);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
