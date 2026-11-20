<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiStateCommand;
use Semitexa\Dev\Application\Service\Ai\Charter\Charter;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;
use Semitexa\Dev\Application\Service\Ai\State\AuditRecord;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

// verify:accept-test-change FrameworkRubric and ai:state score are removed, and their tests with them: the owner chose one scoring system, ai:decision progress-one-system.
final class AiStateBacklogTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-state-backlog-' . uniqid('', true);
        mkdir($this->root . '/src/modules', 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->originalCwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        putenv('SEMITEXA_STATE_WITH_FRAMEWORK');
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function show(array $input = []): array
    {
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore(), AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore()]));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($input, ['interactive' => false]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        return $result;
    }

    /** @param list<string> $refs */
    private function file(string $id, array $refs, TaskStatus $status = TaskStatus::NEW): void
    {
        (new TaskStore())->save(new Task($id, 'ep-a', 't', $status, '', 'low', $refs, null, $id, self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK, $status === TaskStatus::DONE ? new TaskProof('rcpt-1', self::NOW, ['phpunit:X'], []) : null));
    }

    public function test_a_fresh_project_is_not_measured(): void
    {
        $result = $this->show();

        self::assertSame('work not measured · right not measured · fast not measured', $result['line']);
    }

    public function test_it_counts_the_projects_tasks_and_leaves_the_frameworks_out(): void
    {
        $this->file('tk-project', ['src/modules/Shop/X.php'], TaskStatus::DONE);
        $this->file('tk-framework', ['packages/semitexa-orm/src/Y.php']);

        self::assertStringStartsWith('work 100% (1/1)', (string) $this->show()['line']);
    }

    public function test_the_option_counts_the_framework(): void
    {
        $this->file('tk-project', ['src/modules/Shop/X.php'], TaskStatus::DONE);
        $this->file('tk-framework', ['packages/semitexa-orm/src/Y.php']);

        $result = $this->show(['--with-framework' => true]);

        self::assertStringStartsWith('work 50% (1/2, 1 open)', (string) $result['line']);
    }

    public function test_a_clean_audit_reads_in_the_line(): void
    {
        (new AuditStore())->record(new AuditRecord(Phase::RIGHT, gmdate(DATE_ATOM), 'agent', 'the Shop module', false));

        self::assertSame('work not measured · right nothing open (audited today) · fast not measured', $this->show()['line']);
    }

    public function test_a_person_gets_the_line_alone(): void
    {
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore(), AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore()]));
        $tester = new CommandTester($command);

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        self::assertSame('work not measured · right not measured · fast not measured', trim($tester->getDisplay()));
    }

    private function charter(SuccessSign ...$signs): void
    {
        (new CharterStore())->save(new Charter(goal: 'a shop', signs: array_values($signs)));
    }

    public function test_the_charter_signs_stand_beside_the_numbers_and_leave_the_percent_alone(): void
    {
        $this->file('tk-project', ['src/modules/Shop/X.php'], TaskStatus::DONE);
        $this->file('tk-open', ['src/modules/Shop/Y.php']);
        $before = $this->show();
        $this->charter(new SuccessSign('s1', 'a buyer pays in under a minute'), new SuccessSign('s2', 'p95 under 50 ms', 'requests.p95'));

        $result = $this->show();

        self::assertSame($before['line'], $result['line'], 'signs never enter the percent');
        self::assertSame($before['backlog'], $result['backlog']);
        self::assertSame([
            ['id' => 's1', 'text' => 'a buyer pays in under a minute', 'kind' => 'judged', 'source' => null],
            ['id' => 's2', 'text' => 'p95 under 50 ms', 'kind' => 'computed', 'source' => 'requests.p95'],
        ], $result['signs']);
        self::assertArrayNotHasKey('charter_error', $result);
    }

    public function test_no_charter_is_no_signs(): void
    {
        $result = $this->show();

        self::assertSame('work not measured · right not measured · fast not measured', $result['line']);
        self::assertArrayHasKey('signs', $result, 'no charter still answers, with no signs');
        self::assertSame([], $result['signs']);
        self::assertArrayNotHasKey('charter_error', $result);
    }

    public function test_a_person_reads_the_signs_under_the_line(): void
    {
        $this->charter(new SuccessSign('s1', 'a buyer pays in under a minute'), new SuccessSign('s2', 'p95 under 50 ms', 'requests.p95'));
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore(), AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore()]));
        $tester = new CommandTester($command);

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        self::assertSame(
            "work not measured · right not measured · fast not measured\n"
            . "signs of success (the charter's; not in the percent):\n"
            . "  s1 a buyer pays in under a minute (judged)\n"
            . '  s2 p95 under 50 ms (computed from requests.p95)',
            trim($tester->getDisplay()),
        );
    }

    public function test_a_charter_that_does_not_read_is_said_and_the_numbers_stand(): void
    {
        $this->file('tk-project', ['src/modules/Shop/X.php'], TaskStatus::DONE);
        is_dir($this->root . '/var/ai-work') || mkdir($this->root . '/var/ai-work', 0775, true);
        file_put_contents($this->root . '/' . CharterStore::PATH, '{"schema_version":"nope"}');

        $result = $this->show();

        self::assertIsString($result['line']);
        self::assertStringStartsWith('work 100% (1/1)', $result['line']);
        self::assertSame([], $result['signs']);
        self::assertIsString($result['charter_error']);
        self::assertStringContainsString(CharterStore::PATH, $result['charter_error']);
    }

    public function test_a_task_file_that_does_not_read_is_an_error_not_a_number(): void
    {
        $store = new TaskStore();
        $this->file('tk-a', []);
        file_put_contents($store->pathFor('tk-b'), (string) json_encode(['id' => 'tk-b', 'status' => 'bogus']));
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => $store, AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore()]));
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('bogus', $tester->getDisplay());
    }

    public function test_a_truncated_task_file_is_an_error_not_a_lower_count(): void
    {
        $store = new TaskStore();
        $this->file('tk-a', []);
        file_put_contents($store->pathFor('tk-b'), '{"id":"tk-b"');
        $command = new AiStateCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => $store, AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore()]));
        $tester = new CommandTester($command);

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('tk-b', $tester->getDisplay());
    }

    public function test_the_environment_turns_the_option_on(): void
    {
        $this->file('tk-framework', ['packages/semitexa-orm/src/Y.php']);
        putenv('SEMITEXA_STATE_WITH_FRAMEWORK=1');

        $result = $this->show();

        self::assertTrue($result['backlog']['with_framework']);
        self::assertStringStartsWith('work 0% (0/1, 1 open)', (string) $result['line'], 'the framework task is counted');
    }

    public function test_the_environment_set_to_off_leaves_it_off(): void
    {
        $this->file('tk-framework', ['packages/semitexa-orm/src/Y.php']);
        putenv('SEMITEXA_STATE_WITH_FRAMEWORK=0');

        $result = $this->show();

        self::assertFalse($result['backlog']['with_framework']);
        self::assertStringStartsWith('work not measured', (string) $result['line'], 'the framework task is left out');
    }
}
