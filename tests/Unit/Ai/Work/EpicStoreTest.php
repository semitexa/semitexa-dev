<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Semitexa\Dev\Tests\Support\CapturingLogger;

class EpicStoreTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-epic-' . uniqid();
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
