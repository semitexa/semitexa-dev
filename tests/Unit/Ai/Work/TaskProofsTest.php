<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Work;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
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
     * @param list<array<string, string>> $targets
     * @param array<string, ?string> $tree
     * @param array<string, mixed> $extra
     */
    private function receipt(string $verdict, array $targets, array $tree = ['src/X.php' => 'a'], string $trace = 'tk-a', array $extra = []): string
    {
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
        $id = $this->receipt('pass', [self::LINT, self::PHPUNIT]);

        $proof = $this->find();

        self::assertSame($id, $proof?->receipt);
        self::assertSame(['phpunit:X'], $proof->tests);
    }

    public function test_e2e_counts_as_a_test(): void
    {
        $this->receipt('pass', [['id' => 'e2e:x', 'type' => 'e2e', 'status' => 'pass']]);

        self::assertSame(['e2e:x'], $this->find()?->tests);
    }

    public function test_no_run_is_no_proof(): void
    {
        self::assertNull($this->find());
    }

    public function test_a_run_for_another_task_proves_nothing_here(): void
    {
        $this->receipt('pass', [self::PHPUNIT], trace: 'tk-other');

        self::assertNull($this->find());
    }

    public function test_a_task_with_its_own_trace_id_finds_its_run(): void
    {
        $this->receipt('pass', [self::PHPUNIT], trace: 'trace-a');

        self::assertNotNull($this->find($this->task(trace: 'trace-a')));
    }

    public function test_code_with_no_test_is_not_proven_by_a_lint(): void
    {
        $this->receipt('pass', [self::LINT]);

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
        $this->receipt('pass', [self::LINT], [$path => 'a']);

        self::assertSame([], $this->find()?->tests);
    }

    public function test_docs_beside_code_still_need_a_test(): void
    {
        $this->receipt('pass', [self::LINT], ['docs/guide.md' => 'a', 'src/X.php' => 'b']);

        self::assertNull($this->find());
    }

    public function test_a_red_run_after_a_green_one_means_not_proven(): void
    {
        $this->receipt('pass', [self::PHPUNIT]);
        $this->receipt('fail', [['id' => 'phpunit:X', 'type' => 'phpunit', 'status' => 'fail']]);

        self::assertNull($this->find());
    }

    public function test_a_run_with_nothing_to_verify_after_the_commit_changes_nothing(): void
    {
        $id = $this->receipt('pass', [self::PHPUNIT]);
        $this->receipt('nothing_to_verify', [], []);

        self::assertSame($id, $this->find()?->receipt);
    }

    public function test_a_file_edited_during_the_run_is_no_proof(): void
    {
        $this->receipt('pass', [self::PHPUNIT], extra: ['changed_during_run' => ['src/X.php']]);

        self::assertNull($this->find());
    }

    public function test_an_unreadable_receipt_is_passed_over(): void
    {
        $id = $this->receipt('pass', [self::PHPUNIT]);
        file_put_contents($this->root . '/' . VerifyReceipts::DIR . '/rcpt-20261106-999999-aaaaaa.json', '{');

        self::assertSame($id, $this->find()?->receipt);
    }

    private function commit(string $path, string $content): string
    {
        file_put_contents($this->root . '/' . $path, $content);
        $git = 'git -C ' . escapeshellarg($this->root) . ' -c user.name=t -c user.email=t@example.test -c safe.directory=' . escapeshellarg($this->root);
        exec("{$git} init -q 2>/dev/null; {$git} add -A -- " . escapeshellarg($path) . " && {$git} commit -q -m x");

        return trim((string) shell_exec("{$git} rev-parse HEAD"));
    }

    public function test_the_task_names_the_commit_that_holds_what_the_run_checked(): void
    {
        $sha = $this->commit('Done.php', 'checked');
        $this->receipt('pass', [self::PHPUNIT], ['Done.php' => hash('sha256', 'checked')]);

        self::assertSame(['(project root)' => $sha], $this->find()?->commits);
    }

    public function test_closed_before_committing_it_names_no_commit_of_someone_else(): void
    {
        $this->commit('Other.php', 'someone else');
        file_put_contents($this->root . '/Done.php', 'checked');
        $this->receipt('pass', [self::PHPUNIT], ['Done.php' => hash('sha256', 'checked')]);

        $proof = $this->find();

        self::assertNotNull($proof);
        self::assertSame([], $proof->commits);
    }

    public function test_a_commit_of_other_bytes_is_not_named(): void
    {
        $this->commit('Done.php', 'edited after the run');
        $this->receipt('pass', [self::PHPUNIT], ['Done.php' => hash('sha256', 'checked')]);

        self::assertSame([], $this->find()?->commits);
    }

    public function test_closing_looks_for_proof_and_reopening_drops_it(): void
    {
        $this->receipt('pass', [self::PHPUNIT]);
        $proofs = new TaskProofs($this->root);

        $closed = $proofs->settle($this->task()->with(status: TaskStatus::DONE), TaskStatus::DONE);
        self::assertNotNull($closed->proof);

        self::assertNotNull($proofs->settle($closed, null)->proof, 'an edit that does not move the status keeps it');
        self::assertNull($proofs->settle($closed, TaskStatus::IN_PROGRESS)->proof);
    }

    public function test_the_proof_survives_a_round_trip_and_later_edits(): void
    {
        $proof = new TaskProof('rcpt-1', self::NOW, ['phpunit:X'], ['packages/x' => 'abc']);
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
