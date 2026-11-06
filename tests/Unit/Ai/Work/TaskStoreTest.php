<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\CapturingLogger;
use Semitexa\Testing\TestCase;

class TaskStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->enterFixtureProjectRoot('ai-work-task');
    }

    public function test_change_works_on_the_task_as_it_is_now_not_as_it_was_read(): void
    {
        $now = '2026-11-06T00:00:00+00:00';
        $store = new TaskStore();
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));
        $stale = $store->get('tk-a');
        $store->save($stale->withApproval(new \Semitexa\Dev\Application\Service\Ai\Work\TaskApproval('owner', 'Так', $now), $now));

        $store->change('tk-a', static fn (Task $current): Task => $current->with(status: TaskStatus::DONE));

        $task = $store->get('tk-a');
        self::assertTrue($task->isApproved(), 'a write from a stale read would have dropped this');
        self::assertSame(TaskStatus::DONE, $task->status);
    }

    public function test_change_of_a_task_that_is_not_there_fails(): void
    {
        $store = new TaskStore();
        $now = '2026-11-06T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));

        $this->expectException(\RuntimeException::class);
        $store->change('tk-none', static fn (Task $current): Task => $current);
    }

    public function test_change_lets_go_of_the_lock_when_the_change_throws(): void
    {
        $store = new TaskStore();
        $now = '2026-11-06T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));

        try {
            $store->change('tk-a', static fn (Task $current): Task => throw new \RuntimeException('no'));
            self::fail('the exception must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertSame('no', $e->getMessage());
        }

        // A lock still held would hang here, on a new file description.
        self::assertSame(TaskStatus::DONE, $store->change('tk-a', static fn (Task $current): Task => $current->with(status: TaskStatus::DONE))->status);
    }

    public function test_change_on_a_project_with_no_tasks_says_the_task_is_missing(): void
    {
        $this->expectExceptionMessage("task 'tk-none' does not exist");
        (new TaskStore())->change('tk-none', static fn (Task $current): Task => $current);
    }

    public function test_the_lock_file_is_open_to_every_user(): void
    {
        $store = new TaskStore();
        $now = '2026-11-06T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));

        $store->change('tk-a', static fn (Task $current): Task => $current);

        self::assertSame(0666, fileperms(dirname($store->pathFor('tk-a')) . '/.lock') & 0777);
    }

    /**
     * Several processes at once, because in one process nothing can interleave.
     * Measured with four writers of 150 changes: 259 of 600 survive without the
     * lock, so this fails at once if it goes.
     */
    public function test_writers_in_parallel_lose_no_change(): void
    {
        $store = new TaskStore();
        $now = '2026-11-06T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], '0', 'tk-a', $now, $now));

        $autoload = dirname((string) (new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php';
        $writer = 'require ' . var_export($autoload, true) . ';'
            . '$store = new ' . TaskStore::class . '();'
            . 'for ($i = 0; $i < 50; $i++) {'
            . '$store->change("tk-a", static fn ($t) => $t->with(nextStep: (string) ((int) $t->nextStep + 1)));'
            . '}';
        $writers = [];
        for ($i = 0; $i < 4; $i++) {
            $writers[] = proc_open([PHP_BINARY, '-r', $writer], [], $pipes, (string) getcwd());
        }
        foreach ($writers as $process) {
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
        }

        self::assertSame('200', $store->get('tk-a')->nextStep);
    }

    public function test_a_bad_status_is_passed_over_unless_strict(): void
    {
        $store = new TaskStore();
        $now = '2026-11-06T00:00:00+00:00';
        $store->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));
        file_put_contents($store->pathFor('tk-b'), (string) json_encode(['id' => 'tk-b', 'status' => 'bogus']));

        self::assertCount(1, $store->list());

        $this->expectException(\InvalidArgumentException::class);
        $store->list(strict: true);
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

    public function test_list_logs_and_skips_a_corrupt_record_instead_of_silently_dropping_it(): void
    {
        $store = new TaskStore();
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Task('tk-ok', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-ok', $now, $now));

        // A partial write / hand-edit leaves a malformed file with a valid id name.
        file_put_contents($this->root . '/' . TaskStore::SUBDIR . '/tk-bad.json', '{ this is not json');

        $logger = new CapturingLogger();
        StaticLoggerBridge::set($logger);
        try {
            $tasks = $store->list();
        } finally {
            StaticLoggerBridge::reset();
        }

        // The listing stays resilient — the good task is still returned...
        $this->assertSame(['tk-ok'], array_map(static fn(Task $t) => $t->id, $tasks));
        // ...but the corrupt one is surfaced, not silently missing.
        $this->assertCount(1, $logger->warnings);
        [$message, $context] = $logger->warnings[0];
        $this->assertSame('Skipping unreadable task record', $message);
        $this->assertStringContainsString('tk-bad.json', (string) $context['file']);
    }
}
