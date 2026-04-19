<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Semitexa\Dev\Ai\Work\ResumeService;
use Semitexa\Dev\Ai\Work\Task;
use Semitexa\Dev\Ai\Work\TaskStatus;
use Semitexa\Dev\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;

class ResumeServiceTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-resume-' . uniqid();
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

    private function newResumeService(TaskStore $tasks, TraceStore $traces): ResumeService
    {
        $service = new ResumeService();
        PropertyInjector::inject($service, new ArrayContainer([
            TaskStore::class  => $tasks,
            TraceStore::class => $traces,
        ]));
        return $service;
    }

    public function test_resume_returns_task_plus_trace_tail(): void
    {
        $tasks = new TaskStore();
        $traces = new TraceStore();

        $traces->openOrCreate('tk-a', topic: 'fix wm', recipe: 'fix_bug');
        $traces->append('tk-a', TraceEventKind::TASK_RESULT, 'one');
        $traces->append('tk-a', TraceEventKind::NOTE, 'two');
        $traces->append('tk-a', TraceEventKind::NOTE, 'three');
        $traces->append('tk-a', TraceEventKind::NEXT_STEP, 'four');
        $traces->append('tk-a', TraceEventKind::VERIFY_RESULT, 'five');

        $now = '2026-04-19T00:00:00+00:00';
        $tasks->save(new Task(
            id:          'tk-a',
            epicId:      'ep-a',
            title:       'fix wm',
            status:      TaskStatus::IN_PROGRESS,
            recipe:      'fix_bug',
            risk:        'medium',
            contextRefs: ['packages/semitexa-platform-wm/resources/js/modules/window-frame.js'],
            nextStep:    'verify slotted content in Chrome',
            traceId:     'tk-a',
            createdAt:   $now,
            updatedAt:   $now,
        ));

        $snapshot = $this->newResumeService($tasks, $traces)->resume('tk-a', 3);

        $this->assertSame('tk-a', $snapshot->task->id);
        $this->assertNotNull($snapshot->traceHeader);
        $this->assertSame('fix wm', $snapshot->traceHeader->topic);
        $this->assertCount(3, $snapshot->tailEvents);
        $this->assertSame(['three', 'four', 'five'], array_map(
            static fn($e) => $e->summary,
            $snapshot->tailEvents,
        ));
        $this->assertNull($snapshot->traceWarning);
    }

    public function test_resume_emits_warning_when_trace_missing(): void
    {
        $tasks = new TaskStore();
        $traces = new TraceStore();

        $now = '2026-04-19T00:00:00+00:00';
        $tasks->save(new Task(
            id:          'tk-a',
            epicId:      'ep-a',
            title:       't',
            status:      TaskStatus::NEW,
            recipe:      'r',
            risk:        'low',
            contextRefs: [],
            nextStep:    null,
            traceId:     'ghost',
            createdAt:   $now,
            updatedAt:   $now,
        ));

        $snapshot = $this->newResumeService($tasks, $traces)->resume('tk-a');
        $this->assertNull($snapshot->traceHeader);
        $this->assertSame([], $snapshot->tailEvents);
        $this->assertNotNull($snapshot->traceWarning);
        $this->assertStringContainsString('ghost', $snapshot->traceWarning);
    }

    public function test_resume_returns_full_history_if_tail_larger_than_events(): void
    {
        $tasks = new TaskStore();
        $traces = new TraceStore();

        $traces->openOrCreate('tk-a');
        $traces->append('tk-a', TraceEventKind::NOTE, 'solo');

        $now = '2026-04-19T00:00:00+00:00';
        $tasks->save(new Task('tk-a', 'ep-a', 't', TaskStatus::NEW, 'r', 'low', [], null, 'tk-a', $now, $now));

        $snapshot = $this->newResumeService($tasks, $traces)->resume('tk-a', 20);
        $this->assertCount(1, $snapshot->tailEvents);
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
