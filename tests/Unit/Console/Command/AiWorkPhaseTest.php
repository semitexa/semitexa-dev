<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiWorkCommand;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\ResumeService;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

final class AiWorkPhaseTest extends TestCase
{
    private string $root;
    private ?string $originalCwd = null;
    private TaskStore $tasks;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-phase-' . uniqid();
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();

        $this->tasks = new TaskStore();
        $traces = new TraceStore();
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $this->tasks]));
        $now = '2026-11-06T00:00:00+00:00';
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, $now, $now));
        $hygiene = new BacklogHygiene();
        PropertyInjector::inject($hygiene, new ArrayContainer([EpicStore::class => $epics, TaskStore::class => $this->tasks]));
        $resume = new ResumeService();
        PropertyInjector::inject($resume, new ArrayContainer([TaskStore::class => $this->tasks, TraceStore::class => $traces]));

        $command = new AiWorkCommand();
        PropertyInjector::inject($command, new ArrayContainer([
            TaskStore::class      => $this->tasks,
            EpicStore::class      => $epics,
            TraceStore::class     => $traces,
            ResumeService::class  => $resume,
            BacklogHygiene::class => $hygiene,
        ]));
        $this->tester = new CommandTester($command);
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param array<string, mixed> $options */
    private function start(string $id, array $options = []): int
    {
        return $this->tester->execute(['action' => 'start', '--id' => $id, '--epic' => 'ep-a', '--title' => 'do it', '--json' => true] + $options);
    }

    public function test_work_filed_with_a_phase_is_still_an_idea(): void
    {
        self::assertSame(0, $this->start('tk-a', ['--phase' => 'work']));

        $task = $this->tasks->get('tk-a');
        self::assertSame(Phase::WORK, $task->phase);
        self::assertFalse($task->isApproved());
    }

    public function test_a_right_ticket_counts_at_once(): void
    {
        $this->start('tk-a', ['--phase' => 'right']);

        self::assertTrue($this->tasks->get('tk-a')->isApproved());
    }

    public function test_a_bug_recipe_is_work_and_counts_at_once(): void
    {
        $this->start('tk-a', ['--recipe' => 'fix_bug']);

        $task = $this->tasks->get('tk-a');
        self::assertSame(Phase::WORK, $task->phase);
        self::assertTrue($task->isApproved());
    }

    public function test_a_task_filed_without_a_phase_has_none(): void
    {
        $this->start('tk-a');

        self::assertNull($this->tasks->get('tk-a')->phase);
    }

    public function test_start_refuses_an_unknown_phase_and_files_nothing(): void
    {
        self::assertSame(1, $this->start('tk-a', ['--phase' => 'later']));
        self::assertFalse($this->tasks->exists('tk-a'));
    }

    public function test_update_sets_the_phase_and_does_not_approve(): void
    {
        $this->start('tk-a');

        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--phase' => 'right', '--json' => true]);

        self::assertSame(0, $this->tester->getStatusCode());
        $task = $this->tasks->get('tk-a');
        self::assertSame(Phase::RIGHT, $task->phase);
        self::assertFalse($task->isApproved());
    }

    public function test_update_refuses_an_unknown_phase(): void
    {
        $this->start('tk-a', ['--phase' => 'work']);

        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--phase' => 'later', '--json' => true]);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame(Phase::WORK, $this->tasks->get('tk-a')->phase);
    }
}
