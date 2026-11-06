<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskNextCommands;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProofs;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

final class TaskProofsTest extends TestCase
{
    private const NOW = '2026-11-06T00:00:00+00:00';
    private const PHPUNIT = ['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'pass'];
    private const LINT = ['id' => 'syntax:x', 'type' => 'syntax', 'status' => 'pass'];

    private string $root;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-task-proofs-' . uniqid('', true);
        mkdir($this->root . '/' . VerifyReceipts::DIR, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function task(string $id = 'tk-a', string $trace = 'tk-a'): Task
    {
        return new Task($id, 'ep-a', 't', TaskStatus::IN_PROGRESS, '', 'low', [], null, $trace, self::NOW, self::NOW);
    }

    /**
     * Writes the files as the run saw them, and the receipt of that run.
     *
     * @param list<array<string, string>> $targets
     * @param array<string, string> $files
     * @param array<string, mixed> $extra
     */
    private function run_(string $verdict, array $targets, array $files = ['Code.php' => 'code'], string $trace = 'tk-a', array $extra = []): string
    {
        $tree = [];
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0755, true);
            }
            file_put_contents($this->root . '/' . $path, $content);
            $tree[$path] = hash('sha256', $content);
        }
        $id = sprintf('rcpt-20261106-%06d-aaaaaa', ++$this->sequence);
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . "/{$id}.json", (string) json_encode($extra + [
            'id' => $id, 'generated_at' => self::NOW, 'run_by' => ['agent_session' => 's', 'trace' => $trace],
            'verdict' => $verdict, 'targets' => $targets, 'tree' => $tree, 'changed_during_run' => [],
        ]));

        return $id;
    }

    private function find(?Task $task = null): ?TaskProof
    {
        return (new TaskProofs($this->root))->find($task ?? $this->task());
    }

    public function test_a_passed_test_is_the_proof(): void
    {
        $id = $this->run_('pass', [self::LINT, self::PHPUNIT]);

        $proof = $this->find();

        self::assertSame($id, $proof?->receipt);
        self::assertSame(['phpunit:X'], $proof->tests);
    }

    public function test_e2e_counts_as_a_test(): void
    {
        $this->run_('pass', [['id' => 'e2e:x', 'type' => 'e2e', 'status' => 'pass']]);

        self::assertSame(['e2e:x'], $this->find()?->tests);
    }

    public function test_the_whole_tree_ratchet_is_no_test_of_this_code(): void
    {
        $this->run_('pass', [self::LINT, ['id' => 'phpunit:' . ProjectGuardTargets::RATCHET_SUITE, 'type' => 'phpunit', 'status' => 'pass']]);

        self::assertNull($this->find());
    }

    public function test_the_ratchet_is_left_out_of_the_tests_named(): void
    {
        $this->run_('pass', [self::PHPUNIT, ['id' => 'phpunit:' . ProjectGuardTargets::RATCHET_SUITE, 'type' => 'phpunit', 'status' => 'pass']]);

        self::assertSame(['phpunit:X'], $this->find()?->tests);
    }

    public function test_no_run_is_no_proof(): void
    {
        self::assertNull($this->find());
    }

    public function test_a_run_for_another_task_proves_nothing_here(): void
    {
        $this->run_('pass', [self::PHPUNIT], trace: 'tk-other');

        self::assertNull($this->find());
    }

    public function test_a_task_with_its_own_trace_id_finds_its_run(): void
    {
        $this->run_('pass', [self::PHPUNIT], trace: 'trace-a');

        self::assertNotNull($this->find($this->task(trace: 'trace-a')));
    }

    public function test_code_with_no_test_is_not_proven_by_a_lint(): void
    {
        $this->run_('pass', [self::LINT]);

        self::assertNull($this->find());
    }

    /** @return iterable<string, array{string}> */
    public static function nothingToTest(): iterable
    {
        yield 'a page of docs' => ['docs/guide.md'];
        yield 'yaml' => ['config/app.yaml'];
        yield 'env default' => ['.env.default'];
    }

    #[DataProvider('nothingToTest')]
    public function test_docs_or_config_count_on_a_pass_alone(string $path): void
    {
        $this->run_('pass', [self::LINT], [$path => 'a']);

        self::assertSame([], $this->find()?->tests);
    }

    /** @return iterable<string, array{string}> */
    public static function codeThatLooksLikeConfig(): iterable
    {
        yield 'a dot file of php' => ['src/.environment.php'];
        yield 'the lock file' => ['composer.lock'];
    }

    #[DataProvider('codeThatLooksLikeConfig')]
    public function test_these_still_need_a_test(string $path): void
    {
        $this->run_('pass', [self::LINT], [$path => 'a']);

        self::assertNull($this->find());
    }

    public function test_docs_beside_code_still_need_a_test(): void
    {
        $this->run_('pass', [self::LINT], ['docs/guide.md' => 'a', 'Code.php' => 'b']);

        self::assertNull($this->find());
    }

    public function test_a_run_over_a_doc_does_not_excuse_a_red_run_of_code(): void
    {
        $this->run_('fail', [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'fail']]);
        $this->run_('pass', [self::LINT], ['docs/guide.md' => 'a']);

        self::assertNull($this->find());
    }

    public function test_a_run_over_a_doc_does_not_excuse_untested_code(): void
    {
        $this->run_('pass', [self::LINT]);
        $this->run_('pass', [self::LINT], ['docs/guide.md' => 'a']);

        self::assertNull($this->find());
    }

    public function test_tested_code_stays_proven_after_a_run_over_a_doc(): void
    {
        $id = $this->run_('pass', [self::PHPUNIT]);
        $this->run_('pass', [self::LINT], ['docs/guide.md' => 'a']);

        self::assertSame($id, $this->find()?->receipt);
    }

    public function test_a_red_run_after_a_green_one_means_not_proven(): void
    {
        $this->run_('pass', [self::PHPUNIT]);
        $this->run_('fail', [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'fail']]);

        self::assertNull($this->find());
    }

    public function test_a_green_run_after_a_red_one_proves_it(): void
    {
        $this->run_('fail', [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'fail']]);
        $id = $this->run_('pass', [self::PHPUNIT]);

        self::assertSame($id, $this->find()?->receipt);
    }

    public function test_a_run_with_nothing_to_verify_changes_nothing(): void
    {
        $id = $this->run_('pass', [self::PHPUNIT]);
        $this->run_('nothing_to_verify', [], []);

        self::assertSame($id, $this->find()?->receipt);
    }

    public function test_a_file_edited_during_the_run_is_no_proof(): void
    {
        $this->run_('pass', [self::PHPUNIT], extra: ['changed_during_run' => ['Code.php']]);

        self::assertNull($this->find());
    }

    public function test_a_file_edited_after_the_run_is_no_proof(): void
    {
        $this->run_('pass', [self::PHPUNIT]);
        file_put_contents($this->root . '/Code.php', 'edited since');

        self::assertNull($this->find());
    }

    public function test_an_unreadable_receipt_is_passed_over(): void
    {
        $id = $this->run_('pass', [self::PHPUNIT]);
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261106-999999-aaaaaa.json', '{');

        self::assertSame($id, $this->find()?->receipt);
    }

    public function test_closing_looks_for_proof_and_reopening_drops_it(): void
    {
        $this->run_('pass', [self::PHPUNIT]);
        $proofs = new TaskProofs($this->root);

        $closed = $proofs->settle($this->task()->with(status: TaskStatus::DONE), TaskStatus::DONE);
        self::assertNotNull($closed->proof);

        self::assertNotNull($proofs->settle($closed, null)->proof, 'an edit that does not move the status keeps it');
        self::assertNull($proofs->settle($closed, TaskStatus::IN_PROGRESS)->proof);
    }

    public function test_closing_again_after_the_receipts_are_pruned_keeps_the_proof(): void
    {
        $kept = $this->task()->withProof(new TaskProof('rcpt-old', self::NOW, ['phpunit:X'], []));

        self::assertSame('rcpt-old', (new TaskProofs($this->root))->settle($kept, TaskStatus::DONE)->proof?->receipt);
    }

    public function test_closing_again_after_a_red_run_drops_the_proof(): void
    {
        $this->run_('fail', [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'fail']]);
        $kept = $this->task()->withProof(new TaskProof('rcpt-old', self::NOW, ['phpunit:X'], []));

        self::assertNull((new TaskProofs($this->root))->settle($kept, TaskStatus::DONE)->proof);
    }

    private function git(string $args): string
    {
        $git = 'git -C ' . escapeshellarg($this->root) . ' -c user.name=t -c user.email=t@example.test -c safe.directory=' . escapeshellarg($this->root);

        return trim((string) shell_exec("{$git} {$args} 2>/dev/null"));
    }

    private function commit(string $path, string $message): string
    {
        $this->git('init -q');
        $this->git('add -- ' . escapeshellarg($path));
        $this->git('commit -q --allow-empty -m ' . escapeshellarg($message));

        return $this->git('rev-parse HEAD');
    }

    public function test_the_task_names_the_commits_that_name_it(): void
    {
        $this->run_('pass', [self::PHPUNIT]);
        $sha = $this->commit('Code.php', "feat: x\n\nTask: tk-a");

        self::assertSame(['(project root)' => [$sha]], $this->find()?->commits);
    }

    public function test_a_commit_that_does_not_name_the_task_is_not_named(): void
    {
        $this->run_('pass', [self::PHPUNIT]);
        $this->commit('Code.php', "feat: x\n\nTask: tk-other");
        $this->commit('Code.php', "feat: y\n\nTask: tk-a-longer");

        $proof = $this->find();

        self::assertNotNull($proof);
        self::assertSame([], $proof->commits);
    }

    public function test_the_hint_for_an_unproven_task_runs_for_that_task(): void
    {
        $task = $this->task()->with(status: TaskStatus::DONE, contextRefs: ['src/X.php']);

        $hint = TaskNextCommands::for($task)[0];

        self::assertSame('ai:verify', $hint['cmd']);
        self::assertSame(['--trace=tk-a', '--files=src/X.php', '--json'], $hint['args']);
    }

    public function test_a_proven_task_gets_no_such_hint(): void
    {
        $task = $this->task()->with(status: TaskStatus::DONE)->withProof(new TaskProof('r', self::NOW, ['phpunit:X'], []));

        self::assertNotContains('ai:verify', array_column(TaskNextCommands::for($task), 'cmd'));
    }

    public function test_the_proof_survives_a_round_trip_and_later_edits(): void
    {
        $proof = new TaskProof('rcpt-1', self::NOW, ['phpunit:X'], ['packages/x' => ['abc']]);
        $task = Task::fromArray($this->task()->withProof($proof)->toArray());

        self::assertEquals($proof, $task->proof);
        self::assertEquals($proof, $task->with(title: 'new')->proof);
        self::assertEquals($proof, $task->withApproval(null, self::NOW)->proof);
    }

    public function test_a_stored_proof_with_no_receipt_is_no_proof(): void
    {
        self::assertNull(TaskProof::fromStored(['at' => self::NOW]));
        self::assertNull(TaskProof::fromStored('yes'));
    }
}
