<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Semitexa\Dev\Tests\Support\CapturingLogger;
use Semitexa\Testing\TestCase;

class EpicStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = $this->enterFixtureProjectRoot('ai-work-epic');
    }

    private function newEpicStore(?TaskStore $tasks = null): EpicStore
    {
        $tasks ??= new TaskStore();
        $container = new ArrayContainer([TaskStore::class => $tasks]);
        $store = new EpicStore();
        PropertyInjector::inject($store, $container);
        return $store;
    }

    public function test_save_and_get_round_trip(): void
    {
        $store = $this->newEpicStore();
        $now = '2026-04-19T00:00:00+00:00';
        $epic = new Epic('ep-a', 'title', 'goal', EpicStatus::NEW, $now, $now);
        $store->save($epic);

        $loaded = $store->get('ep-a');
        $this->assertSame('ep-a', $loaded->id);
        $this->assertSame('title', $loaded->title);
        $this->assertSame('goal', $loaded->goal);
        $this->assertSame(EpicStatus::NEW, $loaded->status);
    }

    public function test_get_populates_derived_task_ids(): void
    {
        $tasks = new TaskStore();
        $store = $this->newEpicStore($tasks);
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Epic('ep-a', 't', 'g', EpicStatus::NEW, $now, $now));

        $tasks->save(new Task('tk-1', 'ep-a', 't1', TaskStatus::NEW, 'r', 'low', [], null, 'tk-1', $now, $now));
        $tasks->save(new Task('tk-2', 'ep-a', 't2', TaskStatus::NEW, 'r', 'low', [], null, 'tk-2', $now, $now));
        $tasks->save(new Task('tk-other', 'ep-b', 't3', TaskStatus::NEW, 'r', 'low', [], null, 'tk-other', $now, $now));

        $loaded = $store->get('ep-a');
        $ids = $loaded->taskIds;
        sort($ids);
        $this->assertSame(['tk-1', 'tk-2'], $ids);
    }

    public function test_last_activity_follows_the_tasks_not_the_epic_file(): void
    {
        $tasks = new TaskStore();
        $store = $this->newEpicStore($tasks);
        $epicWritten = '2026-04-27T00:00:00+00:00';
        $store->save(new Epic('ep-a', 't', 'g', EpicStatus::NEW, $epicWritten, $epicWritten));

        $store->save(new Epic('ep-quiet', 't', 'g', EpicStatus::NEW, $epicWritten, $epicWritten));

        $taskMoved = '2026-09-06T10:00:00+00:00';
        $tasks->save(new Task('tk-1', 'ep-a', 't1', TaskStatus::NEW, 'r', 'low', [], null, 'tk-1', $epicWritten, $epicWritten));
        $tasks->save(new Task('tk-2', 'ep-a', 't2', TaskStatus::DONE, 'r', 'low', [], null, 'tk-2', $epicWritten, $taskMoved));

        // The epic file has not been touched since April, but work under it
        // continued in September. Reporting April is what made a live epic
        // read as abandoned in a listing.
        $this->assertSame($epicWritten, $store->get('ep-a')->updatedAt);
        $this->assertSame($taskMoved, $store->get('ep-a')->lastActivity());

        // An epic with no tasks at all still answers, with its own timestamp.
        $this->assertSame($epicWritten, $store->get('ep-quiet')->lastActivity());
    }

    public function test_list_derives_last_activity_for_every_epic(): void
    {
        $tasks = new TaskStore();
        $store = $this->newEpicStore($tasks);
        $old = '2026-04-27T00:00:00+00:00';
        $store->save(new Epic('ep-a', 't', 'g', EpicStatus::NEW, $old, $old));
        $store->save(new Epic('ep-b', 't', 'g', EpicStatus::NEW, $old, $old));

        $tasks->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $old, '2026-09-06T00:00:00+00:00'));
        $tasks->save(new Task('tk-b', 'ep-b', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-b', $old, $old));

        $byId = [];
        foreach ($store->list() as $epic) {
            $byId[$epic->id] = $epic;
        }

        // The single-epic path and the listing path must agree — the listing
        // reads every task in one pass instead of one pass per epic.
        $this->assertSame('2026-09-06T00:00:00+00:00', $byId['ep-a']->lastActivity());
        $this->assertSame($old, $byId['ep-b']->lastActivity());
        $this->assertSame(['tk-a'], $byId['ep-a']->taskIds);
    }

    public function test_last_activity_is_exposed_on_the_api_shape(): void
    {
        $tasks = new TaskStore();
        $store = $this->newEpicStore($tasks);
        $old = '2026-04-27T00:00:00+00:00';
        $store->save(new Epic('ep-a', 't', 'g', EpicStatus::NEW, $old, $old));
        $tasks->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $old, '2026-09-07T00:00:00+00:00'));

        $row = $store->get('ep-a')->toArray();
        $this->assertSame('2026-09-07T00:00:00+00:00', $row['last_activity_at']);
        $this->assertSame($old, $row['updated_at']);

        // Derived, never persisted: the epic file must not have to be rewritten
        // every time one of its tasks moves.
        $this->assertArrayNotHasKey('last_activity_at', $store->get('ep-a')->toFileArray());
    }

    public function test_missing_epic_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->newEpicStore()->get('ep-missing');
    }

    public function test_list_sorts_by_created_at(): void
    {
        $store = $this->newEpicStore();
        $store->save(new Epic('ep-a', 'A', '', EpicStatus::NEW, '2026-04-19T00:00:03+00:00', '2026-04-19T00:00:03+00:00'));
        $store->save(new Epic('ep-b', 'B', '', EpicStatus::NEW, '2026-04-19T00:00:01+00:00', '2026-04-19T00:00:01+00:00'));
        $store->save(new Epic('ep-c', 'C', '', EpicStatus::NEW, '2026-04-19T00:00:02+00:00', '2026-04-19T00:00:02+00:00'));

        $ids = array_map(static fn(Epic $e) => $e->id, $store->list());
        $this->assertSame(['ep-b', 'ep-c', 'ep-a'], $ids);
    }

    public function test_persisted_file_does_not_contain_task_ids(): void
    {
        $tasks = new TaskStore();
        $store = $this->newEpicStore($tasks);
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Epic('ep-a', 't', 'g', EpicStatus::NEW, $now, $now));
        $tasks->save(new Task('tk-1', 'ep-a', 't1', TaskStatus::NEW, 'r', 'low', [], null, 'tk-1', $now, $now));

        $raw = file_get_contents($store->pathFor('ep-a'));
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('task_ids', $decoded);
    }

    public function test_reject_invalid_id(): void
    {
        $store = $this->newEpicStore();
        $this->expectException(\InvalidArgumentException::class);
        $store->exists('BAD ID');
    }

    public function test_list_logs_and_skips_a_corrupt_record_instead_of_silently_dropping_it(): void
    {
        $store = $this->newEpicStore();
        $now = '2026-04-19T00:00:00+00:00';
        $store->save(new Epic('ep-ok', 'title', 'goal', EpicStatus::NEW, $now, $now));

        file_put_contents($this->root . '/' . EpicStore::SUBDIR . '/ep-bad.json', '{ this is not json');

        $logger = new CapturingLogger();
        StaticLoggerBridge::set($logger);
        try {
            $epics = $store->list();
        } finally {
            StaticLoggerBridge::reset();
        }

        $this->assertSame(['ep-ok'], array_map(static fn(Epic $e) => $e->id, $epics));
        $this->assertCount(1, $logger->warnings);
        [$message, $context] = $logger->warnings[0];
        $this->assertSame('Skipping unreadable epic record', $message);
        $this->assertStringContainsString('ep-bad.json', (string) $context['file']);
    }

}
