<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Application\Console\Command\AiEpicCommand;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves ai:epic receives its collaborators via the container-level
 * #[InjectAsReadonly] property-injection channel — the same channel used
 * for every other Semitexa service. No constructor DI, no nullable
 * fallbacks, and no manual wiring at the command.
 */
class AiEpicCommandTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-epic-cmd-' . uniqid();
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
    private function buildWiredCommand(EpicStore $epics, TaskStore $tasks, TraceAutoAppender $appender): AiEpicCommand
    {
        $container = new ArrayContainer([
            EpicStore::class         => $epics,
            TraceAutoAppender::class => $appender,
            BacklogHygiene::class    => $this->newHygiene($epics, $tasks),
        ]);
        $command = new AiEpicCommand();
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

    private function newTraceAppender(TraceStore $traces): TraceAutoAppender
    {
        $appender = new TraceAutoAppender();
        PropertyInjector::inject($appender, new ArrayContainer([TraceStore::class => $traces]));
        return $appender;
    }

    public function test_boots_without_any_collaborators(): void
    {
        $command = new AiEpicCommand();
        $this->assertSame('ai:epic', $command->getName());
    }

    public function test_start_persists_into_injected_epic_store(): void
    {
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $command = $this->buildWiredCommand($epics, $tasks, $this->newTraceAppender($traces));

        $tester = new CommandTester($command);
        $tester->execute([
            'action'  => 'start',
            '--id'    => 'ep-a',
            '--title' => 'Ship it',
            '--goal'  => 'Do the thing',
            '--json'  => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertTrue($epics->exists('ep-a'));

        $loaded = $epics->get('ep-a');
        $this->assertSame('Ship it', $loaded->title);
        $this->assertSame('Do the thing', $loaded->goal);
    }

    public function test_list_reads_from_injected_epic_store(): void
    {
        $tasks = new TaskStore();
        $epics = $this->newEpicStore($tasks);
        $traces = new TraceStore();
        $command = $this->buildWiredCommand($epics, $tasks, $this->newTraceAppender($traces));

        $start = new CommandTester($command);
        $start->execute([
            'action'  => 'start',
            '--id'    => 'ep-list-reads',
            '--title' => 'Second initiative for the listing test',
            '--goal'  => 'Verify that ai:epic list surfaces epics written to the store',
        ]);
        $this->assertSame(0, $start->getStatusCode());

        $list = new CommandTester($command);
        $list->execute([
            'action' => 'list',
            '--json' => true,
        ]);
        $this->assertSame(0, $list->getStatusCode());

        $envelope = json_decode(trim($list->getDisplay()), true);
        $this->assertIsArray($envelope);
        $this->assertSame('semitexa.ai-work.epic-list/v1', $envelope['artifact']);
        $this->assertSame(1, $envelope['epic_count']);
        $this->assertSame('ep-list-reads', $envelope['epics'][0]['id']);
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
