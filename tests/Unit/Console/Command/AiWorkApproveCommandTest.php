<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiWorkApproveCommand;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

final class AiWorkApproveCommandTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    private string $root;
    private ?string $originalCwd = null;
    private TaskStore $tasks;
    private TraceStore $traces;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-work-approve-' . uniqid();
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();

        $this->tasks = new TaskStore();
        $this->traces = new TraceStore();
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => $this->tasks]));
        $epics->save(new Epic('ep-a', 'T', 'G', EpicStatus::NEW, self::NOW, self::NOW));

        $command = new AiWorkApproveCommand();
        PropertyInjector::inject($command, new ArrayContainer([
            TaskStore::class  => $this->tasks,
            EpicStore::class  => $epics,
            TraceStore::class => $this->traces,
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

    private function task(string $id, TaskStatus $status = TaskStatus::NEW, ?TaskApproval $approval = null): void
    {
        $this->traces->openOrCreate($id, topic: 't', recipe: null);
        $this->tasks->save(new Task($id, 'ep-a', 't', $status, '', 'low', [], null, $id, self::NOW, self::NOW, $approval));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function run_(array $input): array
    {
        $this->tester->execute($input);
        $result = json_decode($this->tester->getDisplay(), true);
        self::assertIsArray($result);

        return $result;
    }

    public function test_a_task_with_no_approval_on_disk_is_an_idea(): void
    {
        // A backlog written before approval existed has no such field at all.
        $task = Task::fromArray(['id' => 'tk-old', 'status' => 'done']);

        self::assertFalse($task->isApproved());
    }

    public function test_an_incomplete_stored_approval_is_not_an_approval(): void
    {
        self::assertFalse(Task::fromArray(['id' => 'tk-a', 'approval' => ['by' => 'owner']])->isApproved());
        self::assertFalse(Task::fromArray(['id' => 'tk-a', 'approval' => 'yes'])->isApproved());
    }

    public function test_approves_a_task_and_keeps_who_said_what(): void
    {
        $this->task('tk-a');

        $result = $this->run_(['--id' => 'tk-a', '--words' => 'Так, робимо']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertSame(['tk-a'], $result['changed']);
        $approval = $this->tasks->get('tk-a')->approval;
        self::assertNotNull($approval);
        self::assertSame('owner', $approval->by);
        self::assertSame('Так, робимо', $approval->words);
    }

    public function test_the_approval_survives_later_updates_of_the_task(): void
    {
        $this->task('tk-a');
        $this->run_(['--id' => 'tk-a', '--words' => 'Так']);

        $this->tasks->save($this->tasks->get('tk-a')->with(status: TaskStatus::IN_PROGRESS, updatedAt: self::NOW));

        self::assertTrue($this->tasks->get('tk-a')->isApproved());
    }

    public function test_an_epic_takes_its_tasks_but_not_the_discarded_ones(): void
    {
        $this->task('tk-new');
        $this->task('tk-done', TaskStatus::DONE);
        $this->task('tk-gone', TaskStatus::DISCARDED);

        $result = $this->run_(['--epic' => 'ep-a', '--words' => 'Затверджую']);

        self::assertEqualsCanonicalizing(['tk-new', 'tk-done'], $result['changed']);
        self::assertFalse($this->tasks->get('tk-gone')->isApproved());
    }

    public function test_an_epic_leaves_an_earlier_approval_as_it_was(): void
    {
        $this->task('tk-a', approval: new TaskApproval('owner', 'Раніше', self::NOW));

        $result = $this->run_(['--epic' => 'ep-a', '--words' => 'Потім']);

        self::assertSame(['tk-a'], $result['unchanged']);
        self::assertSame('Раніше', $this->tasks->get('tk-a')->approval?->words);
    }

    public function test_revoke_returns_the_task_to_ideas(): void
    {
        $this->task('tk-a', approval: new TaskApproval('owner', 'Так', self::NOW));

        $result = $this->run_(['--id' => 'tk-a', '--revoke' => true, '--words' => 'Відкладаємо']);

        self::assertSame('revoked', $result['action']);
        self::assertFalse($this->tasks->get('tk-a')->isApproved());
    }

    public function test_the_trace_keeps_the_words(): void
    {
        $this->task('tk-a');

        $this->run_(['--id' => 'tk-a', '--words' => 'Так, робимо']);

        // A trace is NDJSON with Unicode escaped, so it is read back, not searched raw.
        $lines = file($this->root . '/var/ai-traces/tk-a.ndjson', FILE_IGNORE_NEW_LINES) ?: [];
        $read = implode("\n", array_map(static fn (string $l): string => (string) json_encode(json_decode($l), JSON_UNESCAPED_UNICODE), $lines));
        self::assertStringContainsString('Так, робимо', $read);
    }

    public function test_refuses_without_the_owners_words(): void
    {
        $this->task('tk-a');

        $this->run_(['--id' => 'tk-a']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertFalse($this->tasks->get('tk-a')->isApproved());
    }

    public function test_refuses_both_targets_and_neither(): void
    {
        $this->task('tk-a');

        $this->run_(['--id' => 'tk-a', '--epic' => 'ep-a', '--words' => 'Так']);
        self::assertSame(1, $this->tester->getStatusCode());
        $this->run_(['--words' => 'Так']);
        self::assertSame(1, $this->tester->getStatusCode());
        self::assertFalse($this->tasks->get('tk-a')->isApproved());
    }

    public function test_an_unknown_task_or_epic_is_an_error(): void
    {
        self::assertStringContainsString('does not exist', (string) $this->run_(['--id' => 'tk-none', '--words' => 'Так'])['error']);
        self::assertStringContainsString('does not exist', (string) $this->run_(['--epic' => 'ep-none', '--words' => 'Так'])['error']);
    }
}
