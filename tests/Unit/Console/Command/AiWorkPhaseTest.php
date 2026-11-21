<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiWorkCommand;
use Semitexa\Dev\Application\Service\Ai\Decision\Decision;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStore;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Work\BacklogHygiene;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\ResumeService;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
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
            DecisionStore::class => new DecisionStore(),
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
        self::assertFileDoesNotExist($this->root . '/var/ai-traces/tk-a.ndjson');
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

    /** @param array<string, string> $options */
    private function update(string $id, array $options): void
    {
        $this->tester->execute(['action' => 'update', '--id' => $id, '--json' => true] + $options);
        self::assertSame(0, $this->tester->getStatusCode());
    }

    public function test_a_right_ticket_moved_to_work_is_no_longer_approved(): void
    {
        $this->start('tk-a', ['--phase' => 'right']);

        $this->update('tk-a', ['--phase' => 'work']);

        self::assertFalse($this->tasks->get('tk-a')->isApproved());
    }

    public function test_a_bug_that_turns_out_to_be_a_feature_is_no_longer_approved(): void
    {
        $this->start('tk-a', ['--recipe' => 'fix_bug']);

        $this->update('tk-a', ['--recipe' => 'add_feature']);

        self::assertFalse($this->tasks->get('tk-a')->isApproved());
    }

    public function test_a_right_ticket_moved_to_fast_stays_approved(): void
    {
        $this->start('tk-a', ['--phase' => 'right']);

        $this->update('tk-a', ['--phase' => 'fast']);

        self::assertTrue($this->tasks->get('tk-a')->isApproved());
    }

    public function test_the_owners_approval_survives_a_change_of_phase(): void
    {
        $this->start('tk-a', ['--phase' => 'work']);
        $this->tasks->save($this->tasks->get('tk-a')->withApproval(new TaskApproval('owner', 'Так', '2026-11-06T00:00:00+00:00'), '2026-11-06T00:00:00+00:00'));

        $this->update('tk-a', ['--phase' => 'right']);
        $this->update('tk-a', ['--phase' => 'work']);

        self::assertSame('owner', $this->tasks->get('tk-a')->approval?->by);
    }

    public function test_a_task_that_becomes_a_bug_gets_the_phase_of_one_but_no_approval(): void
    {
        $this->start('tk-a', ['--recipe' => 'add_feature']);

        $this->update('tk-a', ['--recipe' => 'fix_bug']);

        $task = $this->tasks->get('tk-a');
        self::assertSame(Phase::WORK, $task->phase);
        self::assertFalse($task->isApproved());
    }

    public function test_the_activity_log_says_when_an_edit_takes_the_approval_away(): void
    {
        $this->start('tk-a', ['--phase' => 'right']);

        $this->update('tk-a', ['--phase' => 'work']);

        self::assertStringContainsString('returned task to ideas', (string) file_get_contents($this->root . '/var/ai-work/activity.ndjson'));
    }

    public function test_the_activity_log_says_when_the_phase_changes(): void
    {
        $this->start('tk-a');

        $this->update('tk-a', ['--phase' => 'work']);

        self::assertStringContainsString('updated task', (string) file_get_contents($this->root . '/var/ai-work/activity.ndjson'));
    }

    public function test_closing_a_task_through_the_command_keeps_its_proof(): void
    {
        $this->start('tk-a');
        mkdir($this->root . '/' . VerifyReceipts::DIR, 0755, true);
        file_put_contents($this->root . '/Code.php', 'code');
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261106-000001-aaaaaa.json', (string) json_encode([
            'id' => 'rcpt-20261106-000001-aaaaaa', 'generated_at' => '2026-11-06T00:00:00+00:00', 'run_by' => ['trace' => 'tk-a'], 'verdict' => 'pass',
            'targets' => [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'pass']], 'tree' => ['Code.php' => hash('sha256', 'code')], 'changed_during_run' => [],
        ]));

        $this->update('tk-a', ['--status' => 'done']);
        self::assertSame('rcpt-20261106-000001-aaaaaa', $this->tasks->get('tk-a')->proof?->receipt);

        $this->update('tk-a', ['--status' => 'in_progress']);
        self::assertNull($this->tasks->get('tk-a')->proof);
    }

    public function test_update_refuses_an_unknown_phase(): void
    {
        $this->start('tk-a', ['--phase' => 'work']);

        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--phase' => 'later', '--json' => true]);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame(Phase::WORK, $this->tasks->get('tk-a')->phase);
    }

    private const EVIDENCE = 'vendor/bin/phpunit --filter=Leak => FAILURES! Tests: 1, Failures: 1';

    private function stabilize(?string $expiresAt = null, string $id = 'stabilization-2026-11'): void
    {
        (new DecisionStore())->save(new Decision($id, 'Ок даю згоду', 'only blockers count', 'process', DecisionScope::STANDING, '2026-11-20T00:00:00+00:00', expiresAt: $expiresAt ?? gmdate(DATE_ATOM, time() + 3600)));
    }

    private function error(): string
    {
        $answer = json_decode($this->tester->getDisplay(), true);
        self::assertIsArray($answer);

        return (string) ($answer['error'] ?? '');
    }

    public function test_in_the_stabilization_mode_a_bug_without_evidence_is_refused_and_nothing_is_filed(): void
    {
        $this->stabilize();

        self::assertSame(1, $this->start('tk-a', ['--recipe' => 'fix_bug']));

        self::assertFalse($this->tasks->exists('tk-a'));
        self::assertFileDoesNotExist($this->root . '/var/ai-traces/tk-a.ndjson');
        self::assertStringContainsString('stabilization mode', $this->error());
        self::assertStringContainsString('file it without --recipe', $this->error());
    }

    public function test_in_the_stabilization_mode_a_blocker_with_evidence_is_approved_by_rule(): void
    {
        $this->stabilize();

        self::assertSame(0, $this->start('tk-a', ['--recipe' => 'fix_bug', '--blocker' => 'leak', '--evidence' => self::EVIDENCE]));

        $task = $this->tasks->get('tk-a');
        self::assertSame('rule', $task->approval?->by);
        self::assertSame('blocker leak: ' . self::EVIDENCE, $task->approval->words);
        self::assertStringContainsString('"blocker":"leak"', (string) file_get_contents($this->root . '/var/ai-traces/tk-a.ndjson'));
    }

    public function test_in_the_stabilization_mode_a_right_or_fast_ticket_is_an_idea(): void
    {
        $this->stabilize();

        self::assertSame(0, $this->start('tk-right', ['--phase' => 'right']));
        self::assertSame(0, $this->start('tk-fast', ['--phase' => 'fast', '--evidence' => self::EVIDENCE]));
        self::assertSame(0, $this->start('tk-budget', ['--phase' => 'fast', '--blocker' => 'bench-budget', '--evidence' => self::EVIDENCE]));

        self::assertFalse($this->tasks->get('tk-right')->isApproved());
        self::assertFalse($this->tasks->get('tk-fast')->isApproved());
        self::assertTrue($this->tasks->get('tk-budget')->isApproved());
    }

    public function test_an_expired_or_otherwise_named_decision_is_no_stabilization_mode(): void
    {
        $this->stabilize('2026-11-19T00:00:00+00:00');
        $this->stabilize(id: 'focus-week');

        self::assertSame(0, $this->start('tk-a', ['--recipe' => 'fix_bug']));
        self::assertSame(0, $this->start('tk-b', ['--phase' => 'right']));

        self::assertSame('a bug counts from the moment it is filed: ai:decision project-progress-model', $this->tasks->get('tk-a')->approval?->words);
        self::assertTrue($this->tasks->get('tk-b')->isApproved());
    }

    public function test_an_unknown_blocker_class_is_refused_in_any_mode(): void
    {
        self::assertSame(1, $this->start('tk-a', ['--recipe' => 'fix_bug', '--blocker' => 'annoying', '--evidence' => self::EVIDENCE]));
        self::assertStringContainsString("invalid blocker 'annoying'", $this->error());
        self::assertFalse($this->tasks->exists('tk-a'));
    }

    public function test_in_the_stabilization_mode_an_edit_does_not_turn_an_idea_into_a_bug(): void
    {
        $this->stabilize();
        $this->start('tk-a');

        $this->tester->execute(['action' => 'update', '--id' => 'tk-a', '--recipe' => 'fix_bug', '--json' => true]);

        self::assertSame(1, $this->tester->getStatusCode());
        self::assertSame('', $this->tasks->get('tk-a')->recipe);
    }

    public function test_in_the_stabilization_mode_claiming_a_bug_filed_before_it_keeps_its_approval(): void
    {
        $this->start('tk-a', ['--recipe' => 'fix_bug']);
        $this->start('tk-b', ['--phase' => 'right']);
        $this->stabilize();

        $this->update('tk-a', ['--status' => 'in_progress']);
        $this->update('tk-b', ['--phase' => 'fast']);

        self::assertTrue($this->tasks->get('tk-a')->isApproved());
        self::assertFalse($this->tasks->get('tk-b')->isApproved(), 'moved to another phase it is filed anew');
    }
}
