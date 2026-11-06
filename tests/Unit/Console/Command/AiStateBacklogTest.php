<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiStateCommand;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\State\ScoreStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

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
        PropertyInjector::inject($command, new ArrayContainer([ScoreStore::class => new ScoreStore(), TaskStore::class => new TaskStore()]));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute(['action' => 'show'] + $input, ['interactive' => false]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        return $result;
    }

    /** @param list<string> $refs */
    private function file(string $id, array $refs, TaskStatus $status = TaskStatus::NEW): void
    {
        (new TaskStore())->save(new Task($id, 'ep-a', 't', $status, '', 'low', $refs, null, $id, self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK, $status === TaskStatus::DONE ? new TaskProof('rcpt-1', self::NOW, ['phpunit:X'], []) : null));
    }

    public function test_a_fresh_project_is_not_measured_and_sees_no_rubric(): void
    {
        $result = $this->show();

        self::assertSame('work not measured · right not measured · fast not measured', $result['line']);
        self::assertArrayNotHasKey('rubric_line', $result);
        self::assertArrayNotHasKey('phases', $result);
    }

    public function test_it_counts_the_projects_tasks_and_leaves_the_frameworks_out(): void
    {
        $this->file('tk-project', ['src/modules/Shop/X.php'], TaskStatus::DONE);
        $this->file('tk-framework', ['packages/semitexa-orm/src/Y.php']);

        self::assertStringStartsWith('work 100% (1/1)', (string) $this->show()['line']);
    }

    public function test_the_option_counts_the_framework_and_adds_its_rubric(): void
    {
        $this->file('tk-project', ['src/modules/Shop/X.php'], TaskStatus::DONE);
        $this->file('tk-framework', ['packages/semitexa-orm/src/Y.php']);

        $result = $this->show(['--with-framework' => true]);

        self::assertStringStartsWith('work 50% (1/2, 1 open)', (string) $result['line']);
        self::assertArrayHasKey('rubric_line', $result);
        self::assertArrayHasKey('phases', $result);
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
