<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\ResumeService;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Application\Console\Command\AiWorkCommand;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves ai:work receives its collaborators via the container-level
 * #[InjectAsReadonly] property-injection channel — the same channel used
 * for every other Semitexa service. No constructor DI, no nullable
 * fallbacks, and no manual wiring at the command.
 */
class AiWorkCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-cmd-' . uniqid();
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

    /**
     * Build the command exactly the way the framework builds it at runtime:
     * no-arg constructor, then container-driven property injection.
     */
    private function buildWiredCommand(
        TaskStore $tasks,
        EpicStore $epics,
        TraceStore $traces,
        ResumeService $resume,
    ): AiWorkCommand {
        $container = new ArrayContainer([
            TaskStore::class      => $tasks,
            EpicStore::class      => $epics,
            TraceStore::class     => $traces,
            ResumeService::class  => $resume,
            BacklogHygiene::class => $this->newHygiene($epics, $tasks),
        ]);
        $command = new AiWorkCommand();
        PropertyInjector::inject($command, $container);
        return $command;
    }

    private function newHygiene(EpicStore $epics, TaskStore $tasks): BacklogHygiene
    {
        $hygiene = new BacklogHygiene();
        PropertyInjector::inject($hygiene, new ArrayContainer([
            EpicStore::class => $epics,
            TaskStore::class => $tasks,
        ]));
        return $hygiene;
    }

    private function newEpicStore(TaskStore $tasks): EpicStore
    {
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $tasks]));
        return $epics;
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

    public function test_boots_without_any_collaborators(): void
    {
        $command = new AiWorkCommand();
        $this->assertSame('ai:work', $command->getName());
    }

    public function test_start_uses_injected_stores(): void
    {
        $now = '2026-04-19T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand(
            $tasks,
            $epics,
            $traces,
            $this->newResumeService($tasks, $traces),
        );

        $tester = new CommandTester($command);
        $tester->execute([
            'action'  => 'start',
            '--id'    => 'tk-a',
            '--epic'  => 'ep-a',
            '--title' => 'do it',
            '--json'  => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue($tasks->exists('tk-a'));
        $this->assertTrue($traces->exists('tk-a'));

        $envelope = json_decode(trim($tester->getDisplay()), true);
        $this->assertIsArray($envelope);
        $this->assertSame('semitexa.ai-work.task/v1', $envelope['artifact']);
        $this->assertSame('tk-a', $envelope['task']['id']);
    }

    public function test_resume_uses_injected_resume_service(): void
    {
        $now = '2026-04-19T00:00:00+00:00';
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, $now, $now));

        $command = $this->buildWiredCommand(
            $tasks,
            $epics,
            $traces,
            $this->newResumeService($tasks, $traces),
        );

        $start = new CommandTester($command);
        $start->execute([
            'action'  => 'start',
            '--id'    => 'tk-a',
            '--epic'  => 'ep-a',
            '--title' => 'do it',
        ]);
        $this->assertSame(0, $start->getStatusCode());

        $resume = new CommandTester($command);
        $resume->execute([
            'action' => 'resume',
            '--id'   => 'tk-a',
            '--json' => true,
        ]);

        $this->assertSame(0, $resume->getStatusCode());
        $envelope = json_decode(trim($resume->getDisplay()), true);
        $this->assertIsArray($envelope);
        $this->assertSame('semitexa.ai-work.task-resume/v1', $envelope['artifact']);
        $this->assertSame('tk-a', $envelope['task']['id']);
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
