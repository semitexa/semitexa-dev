<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_task_file_written_before_approval_existed_is_an_idea(): void
    {
        $this->writeTaskFile('tk-old', ['trace_id' => 'tk-old', 'status' => 'done']);

        self::assertFalse($this->tasks->get('tk-old')->isApproved());
    }

    /** @param array<string, mixed> $fields */
    private function writeTaskFile(string $id, array $fields): void
    {
        $file = $this->tasks->pathFor($id);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0755, true);
        }
        file_put_contents($file, (string) json_encode(['id' => $id, 'epic_id' => 'ep-a', 'created_at' => self::NOW] + $fields));
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

    public function test_revoking_an_epic_reaches_its_discarded_tasks(): void
    {
        $this->task('tk-a', approval: new TaskApproval('owner', 'Так', self::NOW));
        $this->task('tk-gone', TaskStatus::DISCARDED, new TaskApproval('owner', 'Так', self::NOW));

        $this->run_(['--epic' => 'ep-a', '--revoke' => true, '--words' => 'Відкладаємо']);

        self::assertFalse($this->tasks->get('tk-a')->isApproved());
        self::assertFalse($this->tasks->get('tk-gone')->isApproved());
    }

    public function test_a_discarded_task_cannot_be_approved_by_id(): void
    {
        $this->task('tk-gone', TaskStatus::DISCARDED);

        $result = $this->run_(['--id' => 'tk-gone', '--words' => 'Так']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertStringContainsString('discarded', (string) $result['error']);
        self::assertFalse($this->tasks->get('tk-gone')->isApproved());
    }

    public function test_one_broken_task_does_not_stop_the_epic_and_is_named(): void
    {
        $this->task('tk-a');
        $this->writeTaskFile('tk-broken', ['status' => 'new']); // no trace id: it cannot be saved
        $this->task('tk-z');

        $result = $this->run_(['--epic' => 'ep-a', '--words' => 'Так']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame('partial', $result['status']);
        self::assertArrayHasKey('tk-broken', $result['failed']);
        self::assertEqualsCanonicalizing(['tk-a', 'tk-z'], $result['changed']);
        self::assertTrue($this->tasks->get('tk-a')->isApproved());
        self::assertTrue($this->tasks->get('tk-z')->isApproved());
    }

    public function test_by_names_who_decided(): void
    {
        $this->task('tk-a');

        $this->run_(['--id' => 'tk-a', '--words' => 'Так', '--by' => 'product owner']);

        self::assertSame('product owner', $this->tasks->get('tk-a')->approval?->by);
    }

    public function test_words_that_are_not_utf8_are_refused(): void
    {
        $this->task('tk-a');

        $this->run_(['--id' => 'tk-a', '--words' => "\xC3\x28"]);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertFalse($this->tasks->get('tk-a')->isApproved());
    }

    public function test_the_activity_log_says_when_a_task_is_approved_and_returned(): void
    {
        $this->task('tk-a');

        $this->run_(['--id' => 'tk-a', '--words' => 'Так']);
        $this->run_(['--id' => 'tk-a', '--revoke' => true, '--words' => 'Ні']);

        $log = (string) file_get_contents($this->root . '/var/ai-work/activity.ndjson');
        self::assertStringContainsString('approved task', $log);
        self::assertStringContainsString('returned task to ideas', $log);
    }

    public function test_revoking_a_bug_says_to_discard_it_instead(): void
    {
        $this->tasks->save(new Task('tk-bug', 'ep-a', 't', TaskStatus::NEW, 'fix_bug', 'low', [], null, 'tk-bug', self::NOW, self::NOW, new TaskApproval('rule', 'x', self::NOW)));

        $result = $this->run_(['--id' => 'tk-bug', '--revoke' => true, '--words' => 'Ні']);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertStringContainsString('discard', (string) $result['error']);
        self::assertTrue($this->tasks->get('tk-bug')->isApproved());
    }

    public function test_revoking_a_discarded_bug_still_takes_the_approval_back(): void
    {
        $this->tasks->save(new Task('tk-bug', 'ep-a', 't', TaskStatus::DISCARDED, 'fix_bug', 'low', [], null, 'tk-bug', self::NOW, self::NOW, new TaskApproval('rule', 'x', self::NOW)));

        $this->run_(['--id' => 'tk-bug', '--revoke' => true, '--words' => 'Ні']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertFalse($this->tasks->get('tk-bug')->isApproved());
    }

    public function test_an_epic_with_no_tasks_changes_nothing(): void
    {
        $result = $this->run_(['--epic' => 'ep-a', '--words' => 'Так']);

        self::assertSame(0, $this->tester->getStatusCode());
        self::assertSame([], $result['changed']);
    }

    public function test_revoking_an_epic_leaves_its_bugs(): void
    {
        $this->tasks->save(new Task('tk-bug', 'ep-a', 't', TaskStatus::NEW, 'fix_bug', 'low', [], null, 'tk-bug', self::NOW, self::NOW, new TaskApproval('rule', 'x', self::NOW)));
        $this->task('tk-a', approval: new TaskApproval('owner', 'Так', self::NOW));

        $result = $this->run_(['--epic' => 'ep-a', '--revoke' => true, '--words' => 'Ні']);

        self::assertSame(['tk-a'], $result['changed']);
        self::assertSame(['tk-bug'], $result['unchanged']);
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

    /** @return iterable<string, array{string, string, string}> */
    public static function incompleteApprovals(): iterable
    {
        yield 'who' => ['', 'Так', self::NOW];
        yield 'words' => ['owner', '', self::NOW];
        yield 'when' => ['owner', 'Так', ''];
    }

    #[DataProvider('incompleteApprovals')]
    public function test_an_approval_cannot_be_built_without_who_words_or_when(string $by, string $words, string $at): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TaskApproval($by, $words, $at);
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
