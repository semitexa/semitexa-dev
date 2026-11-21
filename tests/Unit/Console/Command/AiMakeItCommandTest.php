<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Container\PropertyInjector;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\AiMakeItCommand;
use Semitexa\Dev\Application\Service\Ai\Charter\Charter;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterInterview;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Application\Service\Ai\Charter\OwnerQuestions;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;
use Semitexa\Dev\Application\Service\Ai\Decision\Decision;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionScope;
use Semitexa\Dev\Application\Service\Ai\Decision\DecisionStore;
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
        PropertyInjector::inject($command, new ArrayContainer([TaskStore::class => new TaskStore(), EpicStore::class => $this->epics(), AuditStore::class => new AuditStore(), CharterStore::class => new CharterStore(), DecisionStore::class => new DecisionStore()]));

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
        self::assertStringStartsWith('bin/semitexa ai:charter answer', $result['interview']['next']['record']);
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

    public function test_an_interview_goes_on_while_its_answers_are_filed(): void
    {
        $this->epic('ep-a', EpicStatus::NEW);
        (new TaskStore())->save(new Task('tk-idea', 'ep-a', 't', TaskStatus::NEW, '', 'low', [], null, 'tk-idea', self::NOW, self::NOW, null, Phase::WORK));

        $result = $this->run_();

        self::assertSame('empty', $result['state']);
        self::assertSame('goal', $result['interview']['next']['field']);
    }

    public function test_except_passes_a_task_over_and_stays_in_the_steps(): void
    {
        $this->file('tk-a');
        $this->file('tk-b');

        $result = $this->run_(['--except' => ['tk-a']]);

        self::assertSame('tk-b', $result['tasks'][0]['id']);
        self::assertStringContainsString('ai:make-it --except=tk-a again', implode("\n", $result['steps']));
    }

    public function test_excepting_all_that_is_open_waits_and_stops_the_loop(): void
    {
        $this->file('tk-a');

        $result = $this->run_(['--except' => ['tk-a']]);

        self::assertSame(['waiting', false], [$result['state'], $result['go_on']]);
    }

    public function test_the_phase_reaches_the_plan_and_stays_in_the_steps(): void
    {
        $this->file('tk-a');

        self::assertSame('done', $this->run_(['--phase' => 'fast'])['state'], 'a work task is not taken under fast');

        $result = $this->run_(['--phase' => 'work']);

        self::assertSame('tk-a', $result['tasks'][0]['id']);
        self::assertStringContainsString('ai:make-it --phase=work again', implode("\n", $result['steps']));
    }

    public function test_an_unknown_phase_is_an_error(): void
    {
        $command = new AiMakeItCommand();
        $tester = $this->tester($command);

        self::assertSame(1, $tester->execute(['--phase' => 'later'], ['interactive' => false]));
        self::assertStringContainsString('invalid phase', $tester->getDisplay());
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
        self::assertStringContainsString('Record: bin/semitexa ai:charter answer', $tester->getDisplay());
    }

    public function test_a_task_file_that_does_not_read_stops_the_answer(): void
    {
        $this->file('tk-a');
        file_put_contents((new TaskStore())->pathFor('tk-b'), '{"id":"tk-b"');
        $tester = $this->tester(new AiMakeItCommand());

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        $result = json_decode($tester->getDisplay(), true);
        self::assertFalse($result['go_on']);
        self::assertStringContainsString('repair or remove', (string) $result['error']);
    }

    public function test_a_task_with_a_bad_status_stops_the_answer(): void
    {
        $this->file('tk-a');
        file_put_contents((new TaskStore())->pathFor('tk-b'), (string) json_encode(['id' => 'tk-b', 'status' => 'bogus']));
        $tester = $this->tester(new AiMakeItCommand());

        self::assertSame(1, $tester->execute([], ['interactive' => false]));
    }

    public function test_a_person_sees_the_warning(): void
    {
        $this->file('tk-a');
        file_put_contents($this->root . '/' . AuditStore::PATH, "{\n");
        $tester = $this->tester(new AiMakeItCommand());

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        self::assertStringContainsString('Warning:', $tester->getDisplay());
    }

    public function test_an_audit_file_that_does_not_read_is_said_and_stops_nothing(): void
    {
        $this->file('tk-a');
        file_put_contents($this->root . '/' . AuditStore::PATH, "{\n");

        $result = $this->run_();

        self::assertSame('work', $result['state']);
        self::assertStringContainsString('does not read', (string) $result['warning']);
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

    public function test_work_names_what_the_architect_keeps_from_the_default_list(): void
    {
        $this->file('tk-a', ['src/modules/Shop/X.php']);

        $result = $this->run_();

        self::assertSame('work', $result['state']);
        self::assertSame(Charter::DEFAULT_OWNER_DECIDES, $result['owner_decides']);
        self::assertStringContainsString('owner_decides): ' . implode('; ', Charter::DEFAULT_OWNER_DECIDES) . '.', implode("\n", $result['steps']));
    }

    public function test_work_names_the_owners_own_list(): void
    {
        (new CharterStore())->save(new Charter(ownerDecides: ['the price list']));
        $this->file('tk-a', ['src/modules/Shop/X.php']);

        $result = $this->run_();

        self::assertSame(['the price list'], $result['owner_decides']);
        self::assertStringContainsString('owner_decides): the price list.', implode("\n", $result['steps']));
    }

    public function test_work_says_what_the_architect_has_already_decided(): void
    {
        $store = new DecisionStore();
        $store->save(new Decision('ship-weekly', 'Так, щотижня', 'one release a week', 'Release', DecisionScope::STANDING, '2026-11-01T00:00:00+00:00'));
        $store->save(new Decision('no-blog', 'Ні', 'no blog posts', 'docs', DecisionScope::STANDING, '2026-11-01T00:00:00+00:00', revoked: true));
        $store->save(new Decision('old-freeze', 'Так', 'a freeze', 'git', DecisionScope::STANDING, '2026-11-01T00:00:00+00:00', expiresAt: '2026-11-02T00:00:00+00:00'));
        $this->file('tk-a', ['src/modules/Shop/X.php']);

        $result = $this->run_();

        self::assertSame(['active' => 1, 'applies_to' => ['release']], $result['decisions'], 'a revoked or expired decision answers nothing');
        self::assertIsArray($result['steps']);
        self::assertStringContainsString('(active now on: release)', implode("\n", $result['steps']));
    }

    public function test_a_decision_store_that_does_not_read_is_said_and_the_loop_goes_on(): void
    {
        mkdir($this->root . '/' . DecisionStore::SUBDIR, 0775, true);
        file_put_contents($this->root . '/' . DecisionStore::SUBDIR . '/broken.json', '{"schema_version":');
        $this->file('tk-a', ['src/modules/Shop/X.php']);

        $result = $this->run_();

        self::assertSame('work', $result['state']);
        self::assertIsArray($result['decisions']);
        self::assertSame(['error'], array_keys($result['decisions']), 'only why: never an empty list that reads as no decisions');
        self::assertIsString($result['decisions']['error']);
        self::assertStringContainsString('broken.json', $result['decisions']['error']);
        self::assertIsArray($result['steps']);
        self::assertStringContainsString('ai:decision list --applies-to=<tag>. An active decision answers it', implode("\n", $result['steps']));
    }

    public function test_decisions_that_do_not_read_never_turn_the_stabilization_mode_off(): void
    {
        (new DecisionStore())->save(new Decision('stabilization-2026-11', 'Ок даю згоду', 'only blockers count', 'process', DecisionScope::STANDING, '2026-11-20T00:00:00+00:00', expiresAt: gmdate(DATE_ATOM, time() + 3600)));
        file_put_contents($this->root . '/' . DecisionStore::SUBDIR . '/broken.json', '{"schema_version":');
        $this->file('tk-a', status: TaskStatus::DONE);

        $work = $this->run_(['--phase' => 'work']);
        $steps = implode("\n", (array) $work['steps']);

        self::assertSame('done', $work['state']);
        self::assertStringContainsString('stop here and report', $steps);
        self::assertStringNotContainsString('find what this phase owes', $steps);
        self::assertStringContainsString('Stabilization mode assumed, since the decisions cannot be read', (string) $work['say']);
        self::assertSame(['id' => 'unreadable', 'expires_at' => null], $work['stabilization']);
    }

    public function test_a_person_sees_that_the_decisions_do_not_read(): void
    {
        mkdir($this->root . '/' . DecisionStore::SUBDIR, 0775, true);
        file_put_contents($this->root . '/' . DecisionStore::SUBDIR . '/broken.json', '{"schema_version":');
        $this->file('tk-a', ['src/modules/Shop/X.php']);
        $decisions = $this->run_()['decisions'];
        self::assertIsArray($decisions);
        $error = $decisions['error'] ?? null;
        self::assertIsString($error);
        self::assertStringContainsString('broken.json', $error);
        $tester = $this->tester(new AiMakeItCommand());

        $tester->execute([], ['interactive' => true, 'decorated' => false]);

        $lines = array_map('trim', explode("\n", $tester->getDisplay()));
        self::assertContains('Decisions cannot be read: ' . $error, $lines);
        self::assertContains('state: work, go_on: true', $lines);
    }

    public function test_a_charter_that_does_not_read_is_said_beside_approved_work(): void
    {
        mkdir($this->root . '/var/ai-work', 0775, true);
        file_put_contents($this->root . '/' . CharterStore::PATH, '{"schema_version":"nope"}');
        $this->file('tk-a', ['src/modules/Shop/X.php']);
        $error = OwnerQuestions::of(new CharterStore())['error'];
        self::assertIsString($error);
        self::assertStringContainsString(CharterStore::PATH, $error);

        $result = $this->run_();

        self::assertSame('work', $result['state'], 'approved work stays available');
        self::assertSame($error, $result['charter_error']);
        self::assertNull($result['owner_decides']);

        $tester = $this->tester(new AiMakeItCommand());
        $tester->execute([], ['interactive' => true, 'decorated' => false]);
        $lines = array_map('trim', explode("\n", $tester->getDisplay()));
        self::assertContains('Charter cannot be read: ' . $error, $lines);
        self::assertContains('state: work, go_on: true', $lines);
    }

    public function test_a_charter_that_reads_reports_no_error(): void
    {
        $this->file('tk-a', ['src/modules/Shop/X.php']);

        $result = $this->run_();

        self::assertArrayHasKey('charter_error', $result);
        self::assertNull($result['charter_error']);
    }

    public function test_the_stabilization_mode_is_in_the_answer_while_its_decision_is_active(): void
    {
        $this->file('tk-a');
        self::assertNull($this->run_()['stabilization']);

        $until = gmdate(DATE_ATOM, time() + 3600);
        (new DecisionStore())->save(new Decision('stabilization-2026-11', 'Ок даю згоду', 'only blockers count', 'process', DecisionScope::STANDING, '2026-11-20T00:00:00+00:00', expiresAt: $until));
        $result = $this->run_();

        self::assertSame(['id' => 'stabilization-2026-11', 'expires_at' => $until], $result['stabilization']);
        self::assertStringContainsString("Stabilization mode until {$until} (ai:decision stabilization-2026-11)", (string) $result['say']);
        self::assertStringContainsString('file it as an idea', implode("\n", (array) $result['steps']));
    }
}
