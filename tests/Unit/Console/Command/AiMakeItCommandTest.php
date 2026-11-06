<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiMakeItCommand;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Semitexa\Dev\Tests\Support\ArrayContainer;
use Symfony\Component\Console\Tester\CommandTester;

final class AiMakeItCommandTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';

    private string $root;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-ai-make-it-' . uniqid('', true);
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
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function run_(array $input = []): array
    {
        $command = new AiMakeItCommand();
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore()]));
        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute($input, ['interactive' => false]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        return $result;
    }

    /** @param list<string> $refs */
    private function file(string $id, array $refs = [], TaskStatus $status = TaskStatus::NEW): void
    {
        (new TaskStore())->save(new Task($id, 'ep-a', 't', $status, '', 'low', $refs, null, $id, self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK));
    }

    public function test_a_fresh_project_is_told_to_interview(): void
    {
        $result = $this->run_();

        self::assertSame('empty', $result['state']);
        self::assertFalse($result['go_on']);
        self::assertSame('work not measured · right not measured · fast not measured', $result['line']);
    }

    public function test_it_walks_a_backlog_to_the_end(): void
    {
        $this->file('tk-a');

        $first = $this->run_();
        self::assertSame(['work', true, 'tk-a'], [$first['state'], $first['go_on'], $first['tasks'][0]['id']]);

        $store = new TaskStore();
        $store->save($store->get('tk-a')->with(status: TaskStatus::DONE));

        $last = $this->run_();
        self::assertSame(['done', false], [$last['state'], $last['go_on']]);
    }

    public function test_the_option_reaches_the_framework_and_the_steps_keep_it(): void
    {
        $this->file('tk-fw', ['packages/semitexa-orm/src/X.php']);

        self::assertSame('empty', $this->run_()['state']);

        $result = $this->run_(['--with-framework' => true]);
        self::assertSame('work', $result['state']);
        self::assertStringContainsString('ai:make-it --with-framework again', implode("\n", $result['steps']));
    }
}
