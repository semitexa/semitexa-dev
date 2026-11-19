<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskApproval;
use Semitexa\Dev\Application\Service\Ai\Work\TaskNextCommands;
use Semitexa\Dev\Application\Service\Ai\Work\TaskNoProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProof;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProofs;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * A task that counts closes on a proof or on a stated reason, never on
 * neither (tk-triage-unproven-tasks: 17 tasks had been closed with no proof
 * and nothing had said so at the time).
 */
final class TaskCloseNeedsProofTest extends TestCase
{
    private const NOW = '2026-11-19T00:00:00+00:00';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-task-close-' . uniqid('', true);
        mkdir($this->root . '/' . VerifyReceipts::DIR, 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function task(bool $approved = true, string $recipe = ''): Task
    {
        return new Task('tk-a', 'ep-a', 't', TaskStatus::IN_PROGRESS, $recipe, 'low', ['src/X.php'], null, 'tk-a', self::NOW, self::NOW, $approved ? new TaskApproval('owner', 'yes', self::NOW) : null);
    }

    private function reason(string $why = 'a manual acceptance run, no test can stand in for it'): TaskNoProof
    {
        return new TaskNoProof($why, self::NOW, 'claude-1');
    }

    private function passingRun(): void
    {
        file_put_contents($this->root . '/Code.php', 'code');
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261119-000001-aaaaaa.json', (string) json_encode([
            'id' => 'rcpt-20261119-000001-aaaaaa', 'generated_at' => self::NOW, 'run_by' => ['agent_session' => 's', 'trace' => 'tk-a'], 'verdict' => 'pass',
            'targets' => [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'pass']], 'tree' => ['Code.php' => hash('sha256', 'code')], 'changed_during_run' => [],
        ]));
    }

    /** A run of this task over its code that failed, newer than any other receipt. */
    private function failingRun(): void
    {
        file_put_contents($this->root . '/Code.php', 'code');
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261119-000002-bbbbbb.json', (string) json_encode([
            'id' => 'rcpt-20261119-000002-bbbbbb', 'generated_at' => '2026-11-19T00:00:02+00:00', 'run_by' => ['agent_session' => 's', 'trace' => 'tk-a'], 'verdict' => 'fail',
            'targets' => [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'fail']], 'tree' => ['Code.php' => hash('sha256', 'code')], 'changed_during_run' => [],
        ]));
    }

    private function refusal(Task $task, ?TaskNoProof $because = null, ?TaskStatus $to = TaskStatus::DONE, ?string $recipe = null): ?string
    {
        return (new TaskProofs($this->root))->refusalToClose($task, $to, $because, $recipe);
    }

    public function test_a_counted_task_with_no_proof_and_no_reason_is_refused(): void
    {
        $refusal = $this->refusal($this->task());

        self::assertSame(
            "task 'tk-a' has no proof: no passing ai:verify run for it holds a test of its code. Run `bin/semitexa ai:verify --trace=tk-a --files=src/X.php` and close again, or, when no test can prove it, close with `--no-proof-because=\"<why>\"`; the reason is kept on the task.",
            $refusal,
        );
    }

    public function test_only_a_close_is_asked_for_proof(): void
    {
        self::assertNull($this->refusal($this->task(), to: TaskStatus::BLOCKED));
        self::assertNull($this->refusal($this->task(), to: null), 'an edit that moves no status');
    }

    public function test_a_reason_closes_it_and_is_kept_on_the_task(): void
    {
        self::assertNull($this->refusal($this->task(), $this->reason()));

        $closed = (new TaskProofs($this->root))->settle($this->task()->with(status: TaskStatus::DONE), TaskStatus::DONE, $this->reason());

        self::assertNull($closed->proof);
        self::assertSame(['reason' => 'a manual acceptance run, no test can stand in for it', 'at' => self::NOW, 'by' => 'claude-1'], $closed->noProof?->toArray());
    }

    public function test_a_blank_reason_is_no_reason(): void
    {
        self::assertNull(TaskNoProof::because(null, self::NOW));
        self::assertNull(TaskNoProof::because(" \t ", self::NOW));
        self::assertNull(TaskProofs::because('   '));
        self::assertNotNull($this->refusal($this->task(), TaskProofs::because('   ')));
        self::assertSame('why', TaskProofs::because(' why ')?->reason);
    }

    public function test_a_proof_wins_over_a_reason(): void
    {
        $this->passingRun();

        self::assertNull($this->refusal($this->task()), 'proven: nothing more is asked');
        $closed = (new TaskProofs($this->root))->settle($this->task(), TaskStatus::DONE, $this->reason());

        self::assertSame('rcpt-20261119-000001-aaaaaa', $closed->proof?->receipt);
        self::assertNull($closed->noProof, 'a reason beside a real proof would say the task has none');
    }

    public function test_reopening_drops_the_reason(): void
    {
        $proofs = new TaskProofs($this->root);
        $closed = $proofs->settle($this->task(), TaskStatus::DONE, $this->reason());

        self::assertNotNull($proofs->settle($closed, null)->noProof, 'an edit that does not move the status keeps it');
        self::assertNull($proofs->settle($closed, TaskStatus::IN_PROGRESS)->noProof);
    }

    public function test_a_task_closed_with_a_reason_closes_again_and_keeps_it(): void
    {
        $proofs = new TaskProofs($this->root);
        $closed = $proofs->settle($this->task(), TaskStatus::DONE, $this->reason());

        self::assertNull($this->refusal($closed));
        self::assertSame($closed->noProof, $proofs->settle($closed, TaskStatus::DONE)->noProof);
        self::assertSame('a newer one', $proofs->settle($closed, TaskStatus::DONE, $this->reason('a newer one'))->noProof?->reason);
    }

    public function test_history_nobody_approved_closes_freely_and_a_bug_does_not(): void
    {
        self::assertNull($this->refusal($this->task(approved: false)));
        self::assertNotNull($this->refusal($this->task(approved: false, recipe: 'fix_bug')), 'a bug counts with no approval');
        self::assertNotNull($this->refusal($this->task(approved: false), recipe: 'fix_bug'), 'it becomes a bug in this same update');
    }

    public function test_a_proof_kept_after_its_receipts_are_pruned_still_closes(): void
    {
        $kept = $this->task()->withProof(new TaskProof('rcpt-old', self::NOW, ['phpunit:X'], []));

        self::assertNull($this->refusal($kept));
    }

    public function test_the_reason_survives_a_round_trip_and_later_edits(): void
    {
        $task = Task::fromArray($this->task()->withProof(null, $this->reason())->toArray());

        self::assertEquals($this->reason(), $task->noProof);
        self::assertEquals($this->reason(), $task->with(title: 'new')->noProof);
        self::assertEquals($this->reason(), $task->withApproval(null, self::NOW)->noProof);
        self::assertNull(TaskNoProof::fromStored(['reason' => ' ', 'at' => self::NOW]));
        self::assertNull(TaskNoProof::fromStored(['reason' => 'why']));
        self::assertNull(TaskNoProof::fromStored('yes'));
    }

    public function test_a_refusal_for_want_of_proof_names_the_run_and_no_other_refusal_does(): void
    {
        $refusal = (string) $this->refusal($this->task());

        $next = TaskNextCommands::afterRefusal($this->task(), $refusal)['next_command'] ?? [];
        self::assertSame(['ai:verify', ['--trace=tk-a', '--files=src/X.php', '--json']], [$next[0]['cmd'] ?? null, $next[0]['args'] ?? null]);
        self::assertSame([], TaskNextCommands::afterRefusal($this->task(), "task 'tk-a' is held by claude-2"));
    }

    public function test_a_task_closed_with_a_reason_is_not_sent_to_verify_again(): void
    {
        $closed = $this->task()->with(status: TaskStatus::DONE)->withProof(null, $this->reason());

        self::assertSame(['ai:orient', 'ai:trace'], array_column(TaskNextCommands::for($closed), 'cmd'));
        self::assertSame(['ai:verify', 'ai:orient', 'ai:trace'], array_column(TaskNextCommands::for($closed->withProof(null)), 'cmd'), 'with neither it still is');
    }

    /** Review of part B: `--no-proof-because=x` closed a task whose latest run had failed. */
    public function test_a_reason_never_closes_past_a_failing_run(): void
    {
        $this->failingRun();

        $refusal = $this->refusal($this->task(), $this->reason());

        self::assertNotNull($refusal);
        self::assertStringContainsString("task 'tk-a' has no proof: its latest ai:verify run (rcpt-20261119-000002-bbbbbb) failed", $refusal);
        self::assertStringNotContainsString('--no-proof-because', $refusal, 'a failed run is not offered the escape');
    }

    public function test_a_reason_kept_from_an_earlier_close_does_not_close_past_a_failing_run_either(): void
    {
        $closedBefore = (new TaskProofs($this->root))->settle($this->task(), TaskStatus::DONE, $this->reason());
        self::assertNotNull($closedBefore->noProof, 'closed with a reason while no run stood against it');
        $this->failingRun();

        self::assertNotNull($this->refusal($closedBefore));
    }

    public function test_a_pass_after_the_failing_run_closes_it_again(): void
    {
        $this->failingRun();
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261119-000003-cccccc.json', (string) json_encode([
            'id' => 'rcpt-20261119-000003-cccccc', 'generated_at' => '2026-11-19T00:00:03+00:00', 'run_by' => ['agent_session' => 's', 'trace' => 'tk-a'], 'verdict' => 'pass',
            'targets' => [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'pass']], 'tree' => ['Code.php' => hash('sha256', 'code')], 'changed_during_run' => [],
        ]));

        self::assertNull($this->refusal($this->task()));
        self::assertNull($this->refusal($this->task(), $this->reason()));
    }

    /** Review of part B: a pass over no files held no test and still read as a proof. */
    public function test_a_pass_over_no_files_is_no_proof(): void
    {
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261119-000001-aaaaaa.json', (string) json_encode([
            'id' => 'rcpt-20261119-000001-aaaaaa', 'generated_at' => self::NOW, 'run_by' => ['agent_session' => 's', 'trace' => 'tk-a'], 'verdict' => 'pass',
            'targets' => [], 'tree' => [], 'changed_during_run' => [],
        ]));

        self::assertNull((new TaskProofs($this->root))->find($this->task()));
        self::assertNotNull($this->refusal($this->task()));
    }

    /**
     * A run of this task over these files, its receipt numbered so a higher
     * number is a newer run.
     *
     * @param array<string, string> $files path => content, written to the project
     * @param list<string> $changedDuringRun the files the receipt says changed while it ran
     */
    private function receipt(int $number, string $verdict, array $files, array $changedDuringRun = []): void
    {
        $tree = [];
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($this->root . '/' . $path))) {
                mkdir(dirname($this->root . '/' . $path), 0755, true);
            }
            file_put_contents($this->root . '/' . $path, $content);
            $tree[$path] = hash('sha256', $content);
        }
        $id = sprintf('rcpt-20261119-%06d-dddddd', $number);
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/' . $id . '.json', (string) json_encode([
            'id' => $id, 'generated_at' => sprintf('2026-11-19T01:00:%02d+00:00', $number), 'run_by' => ['agent_session' => 's', 'trace' => 'tk-a'], 'verdict' => $verdict,
            'targets' => [['id' => 'docs:reference', 'type' => 'docs', 'status' => $verdict]], 'tree' => $tree, 'changed_during_run' => $changedDuringRun,
        ]));
    }

    /** Review, dev#284: a docs task whose check failed once on README.md never closed again. */
    public function test_a_pass_over_the_files_an_older_docs_fail_was_over_closes_it(): void
    {
        $this->receipt(1, 'fail', ['README.md' => 'draft']);
        $this->receipt(2, 'pass', ['README.md' => 'fixed']);

        self::assertNull($this->refusal($this->task()));
        self::assertNotNull((new TaskProofs($this->root))->find($this->task()));
    }

    public function test_a_pass_over_docs_never_covers_an_older_fail_over_code(): void
    {
        $this->receipt(1, 'fail', ['Code.php' => 'code']);
        $this->receipt(2, 'pass', ['README.md' => 'fixed']);

        $refusal = $this->refusal($this->task(), $this->reason());

        self::assertNotNull($refusal);
        self::assertStringContainsString('rcpt-20261119-000001-dddddd) failed', $refusal);
    }

    public function test_a_pass_over_only_some_of_the_files_a_fail_was_over_does_not_cover_it(): void
    {
        $this->receipt(1, 'fail', ['README.md' => 'draft', 'docs/b.md' => 'draft']);
        $this->receipt(2, 'pass', ['README.md' => 'fixed']);

        self::assertNotNull($this->refusal($this->task(), $this->reason()));
    }

    /** Review, dev#284: a run that failed between the check and the write must not get the reason stored over it. */
    public function test_the_write_never_stores_a_reason_over_a_failing_run(): void
    {
        $this->failingRun();

        $settled = (new TaskProofs($this->root))->settle($this->task(), TaskStatus::DONE, $this->reason());

        self::assertNull($settled->proof);
        self::assertNull($settled->noProof, 'left unproven, so it counts nothing');
    }

    /** Review, dev#284: settle() read the receipts twice, so a run failing between the reads let a reason close it. */
    public function test_one_read_of_the_receipts_decides_both_the_proof_and_the_failing_run(): void
    {
        file_put_contents($this->root . '/Code.php', 'code');
        $pass = ['id' => 'rcpt-20261119-000001-aaaaaa', 'generated_at' => self::NOW, 'run_by' => ['agent_session' => 's', 'trace' => 'tk-a'], 'verdict' => 'pass',
            'targets' => [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'pass']], 'tree' => ['Code.php' => hash('sha256', 'code')], 'changed_during_run' => []];
        $fail = ['verdict' => 'fail', 'id' => 'rcpt-20261119-000002-bbbbbb'] + $pass;
        $reads = 0;
        $proofs = new TaskProofs($this->root, static function () use (&$reads, $pass, $fail): array {
            // The first read sees the pass; a run that failed lands before any later read.
            return ++$reads === 1 ? [$pass] : [$fail, $pass];
        });

        $settled = $proofs->settle($this->task(), TaskStatus::DONE, $this->reason());

        self::assertSame(1, $reads, 'one read');
        self::assertNotNull($settled->proof, 'the read it made held a pass over the code');
        self::assertNull($settled->noProof);
    }

    /** Review, dev#284: a pass whose files changed while it ran covered an older fail it never checked. */
    public function test_a_pass_whose_files_changed_under_it_covers_no_older_fail(): void
    {
        $this->receipt(1, 'fail', ['docs/b.md' => 'draft']);
        $this->receipt(2, 'pass', ['docs/b.md' => 'edited during the run'], ['docs/b.md']);
        $this->receipt(3, 'pass', ['README.md' => 'fixed']);

        self::assertNull((new TaskProofs($this->root))->find($this->task()));
        self::assertNotNull($this->refusal($this->task(), $this->reason()));
    }
}
