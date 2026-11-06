<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiMakeItCommand;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterInterview;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;
use Semitexa\Dev\Application\Service\Ai\State\AuditRecord;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
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
        $tester = $this->tester($command);
        self::assertSame(0, $tester->execute($input, ['interactive' => false]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertIsArray($result);

        return $result;
    }

    private function tester(AiMakeItCommand $command): CommandTester
    {
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore(), EpicStore::class => $this->epics(), AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore()]));

        return new CommandTester($command);
    }

    private function epics(): EpicStore
    {
        $epics = new EpicStore();
        PropertyInjector::inject($epics, new ArrayContainer([TaskStore::class => new TaskStore()]));

        return $epics;
    }

    private function epic(string $id, EpicStatus $status): void
    {
        $this->epics()->save(new Epic($id, 'T', 'G', $status, self::NOW, self::NOW));
    }

    /** @param list<string> $refs */
    private function file(string $id, array $refs = [], TaskStatus $status = TaskStatus::NEW): void
    {
        $this->epic('ep-a', EpicStatus::NEW);
        (new TaskStore())->save(new Task($id, 'ep-a', 't', $status, '', 'low', $refs, null, $id, self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK));
    }

    public function test_the_line_beside_the_answer_knows_the_audits(): void
    {
        (new AuditStore())->record(new AuditRecord(Phase::RIGHT, gmdate(DATE_ATOM), 'agent', 'the Shop module', false));

        self::assertStringContainsString('right nothing open', (string) $this->run_()['line']);
    }

    public function test_an_empty_backlog_hands_over_the_next_question(): void
    {
        $result = $this->run_();

        self::assertSame('goal', $result['interview']['next']['field']);
        self::assertSame(count(CharterInterview::questions()), $result['interview']['open']);
        self::assertStringContainsString('resumes where it stopped', implode("\n", $result['steps']));
    }

    public function test_the_interview_goes_on_from_the_question_left_open(): void
    {
        $store = new CharterStore();
        $store->save($store->get()->with(goal: 'A shop for bikes'));

        self::assertSame('audience', $this->run_()['interview']['next']['field']);
    }

    public function test_a_complete_charter_moves_on_to_the_first_work(): void
    {
        $store = new CharterStore();
        $store->save($store->get()->with(goal: 'g', audience: 'a', problem: 'p', signs: [new SuccessSign('s1', 'a sign')], constraints: [], nonGoals: [], ownerDecides: ['x']));

        $result = $this->run_();

        self::assertNull($result['interview']['next']);
        self::assertStringContainsString('what must work first', implode("\n", $result['steps']));
    }

    public function test_a_broken_charter_is_said_and_not_read_as_complete(): void
    {
        $store = new CharterStore();
        mkdir(dirname($store->path()), 0755, true);
        file_put_contents($store->path(), '{');

        $steps = implode("\n", $this->run_()['steps']);

        self::assertStringContainsString('cannot be read', $steps);
        self::assertStringNotContainsString('charter is complete', $steps);
    }

    public function test_a_backlog_with_work_asks_nothing_of_the_charter(): void
    {
        $this->file('tk-a');

        self::assertNull($this->run_()['interview']);
    }

    public function test_a_person_sees_the_question_to_ask(): void
    {
        $tester = $this->tester(new AiMakeItCommand());

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        self::assertStringContainsString('Ask: What is this project for?', $tester->getDisplay());
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

    public function test_a_shell_that_looks_interactive_still_gets_go_on(): void
    {
        $this->file('tk-a');
        $tester = $this->tester(new AiMakeItCommand());

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        self::assertStringContainsString('state: work, go_on: true', $tester->getDisplay());
    }

    public function test_the_human_output_drops_control_bytes_and_shows_the_next_step(): void
    {
        $this->epic('ep-a', EpicStatus::NEW);
        (new TaskStore())->save(new Task('tk-a', 'ep-a', "bad\x1b[2Jtitle", TaskStatus::NEW, '', 'low', [], 'read this first', 'tk-a', self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK));
        $tester = $this->tester(new AiMakeItCommand());

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        self::assertStringNotContainsString("\x1b", $tester->getDisplay());
        self::assertStringContainsString('next: read this first', $tester->getDisplay());
    }

    public function test_a_task_of_a_closed_epic_is_not_taken(): void
    {
        foreach ([EpicStatus::ARCHIVED, EpicStatus::DISCARDED] as $status) {
            $this->epic('ep-closed', $status);
            (new TaskStore())->save(new Task('tk-x', 'ep-closed', 't', TaskStatus::NEW, '', 'low', [], null, 'tk-x', self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK));

            self::assertSame('empty', $this->run_()['state'], $status->value);
        }
    }

    public function test_a_task_whose_epic_is_gone_is_not_taken(): void
    {
        (new TaskStore())->save(new Task('tk-x', 'ep-gone', 't', TaskStatus::NEW, '', 'low', [], null, 'tk-x', self::NOW, self::NOW, new TaskApproval('owner', 'Так', self::NOW), Phase::WORK));

        self::assertSame('empty', $this->run_()['state']);
    }

    public function test_the_verify_step_names_the_task_so_closing_finds_the_proof(): void
    {
        $this->file('tk-a');

        self::assertStringContainsString('ai:verify --trace=tk-a', implode("\n", $this->run_()['steps']));
    }

    public function test_an_empty_backlog_that_hides_framework_tasks_says_so(): void
    {
        $this->file('tk-fw', ['packages/semitexa-orm/src/X.php']);

        self::assertStringContainsString('--with-framework', (string) $this->run_()['say']);
    }

    public function test_the_environment_option_stays_in_the_steps(): void
    {
        $this->file('tk-fw', ['packages/semitexa-orm/src/X.php']);
        $previous = getenv('SEMITEXA_STATE_WITH_FRAMEWORK');
        putenv('SEMITEXA_STATE_WITH_FRAMEWORK=1');
        try {
            self::assertStringContainsString('ai:make-it --with-framework again', implode("\n", $this->run_()['steps']));
        } finally {
            putenv($previous === false ? 'SEMITEXA_STATE_WITH_FRAMEWORK' : 'SEMITEXA_STATE_WITH_FRAMEWORK=' . $previous);
        }
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
