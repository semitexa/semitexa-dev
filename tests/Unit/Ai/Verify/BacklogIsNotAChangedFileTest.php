<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\ExplicitFiles;
use Semitexa\Dev\Application\Service\Ai\Verify\GitRefChanges;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\ReceiptForCommit;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Verify\TaskCommitFiles;
use Semitexa\Dev\Application\Service\Ai\Verify\UnselectedChanges;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskProofs;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * The backlog is not code (tk-verify-counts-the-backlog-as-changed-files).
 *
 * On a fresh project, 2026-11-19, a bare `ai:verify --trace=<task>` collected 24
 * changed files, 11 of them var/ai-work and var/ai-traces. The run went broad,
 * and the close behind it rewrote the task file the receipt had fingerprinted:
 * the task was counted unproven. The project here is that one in small: a
 * repository at its root, a task's code beside the backlog every ai:work call wrote.
 */
final class BacklogIsNotAChangedFileTest extends TestCase
{
    private const NOW = '2026-11-19T00:00:00+00:00';
    private const CODE = ['src/A.php', 'src/B.php', 'src/C.php', 'src/D.php', 'tests/ATest.php'];
    /** What a start, an approval and a few updates leave behind: eleven files, as measured. */
    private const BACKLOG = [
        'var/ai-work/charter.json', 'var/ai-work/charter.json.lock', 'var/ai-work/activity.ndjson',
        'var/ai-work/tasks/.lock', 'var/ai-work/tasks/tk-a.json', 'var/ai-work/tasks/tk-b.json', 'var/ai-work/tasks/tk-c.json',
        'var/ai-work/epics/ep-a.json', 'var/ai-work/epics/ep-b.json',
        'var/ai-traces/tk-a.ndjson', 'var/ai-traces/tk-b.ndjson',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-backlog-not-code-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o755, true);
        $this->write('composer.json', '{"name": "probe/project"}');
        $this->write('.gitignore', "/var/run/\n"); // as the scaffold has it: receipts are not committed
        $this->git('init -q -b main && %s add -A && %s commit -q -m init');
        foreach (self::CODE as $path) {
            $this->write($path, "<?php // {$path}\n");
        }
        foreach (self::BACKLOG as $path) {
            $this->write($path, "{}\n");
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_changed_files_of_a_tree_with_code_and_backlog_are_the_code(): void
    {
        self::assertSame(self::CODE, $this->collected());
    }

    #[Test]
    public function the_scope_stays_standard(): void
    {
        $classifier = new ChangedFileClassifier();
        $changed = array_map(static fn (string $path): ChangedFile => $classifier->classify($path, ChangedFile::STATUS_ADDED, null), $this->collected());

        $plan = (new VerificationPlanner($this->root, $classifier))->plan($changed, VerificationPlan::SCOPE_STANDARD);

        self::assertSame(VerificationPlan::SCOPE_STANDARD, $plan->effectiveScope, implode(' / ', $plan->expansions));
    }

    #[Test]
    public function a_close_after_such_a_run_is_proven(): void
    {
        $receipt = $this->passingRunOver($this->collected());
        // The close itself: the task file is rewritten, its trace and the activity log grow.
        $this->write('var/ai-work/tasks/tk-a.json', '{"status": "done"}' . "\n");
        file_put_contents($this->root . '/var/ai-traces/tk-a.ndjson', "{\"kind\": \"verify_result\"}\n", FILE_APPEND);
        file_put_contents($this->root . '/var/ai-work/activity.ndjson', "{\"moved\": \"tk-a\"}\n", FILE_APPEND);

        $task = new Task('tk-a', 'ep-a', 't', TaskStatus::IN_PROGRESS, 'fix_bug', 'low', [], null, 'tk-a', self::NOW, self::NOW);
        $closed = (new TaskProofs($this->root))->settle($task, TaskStatus::DONE);

        self::assertSame($receipt, $closed->proof?->receipt);
    }

    /** A project commits var/ai-work beside the code: the gate asks about the code. */
    #[Test]
    public function the_push_gate_does_not_ask_a_run_to_have_read_the_backlog(): void
    {
        $receipt = $this->passingRunOver(self::CODE);
        $this->write('var/ai-work/tasks/tk-a.json', '{"status": "done"}' . "\n"); // the close, after the run
        $this->git('add -A && %s commit -q -m "the task" -m "Task: tk-a"');
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg($this->root) . ' rev-parse HEAD'));

        self::assertSame($receipt, (new ReceiptForCommit($this->root))->find('', $sha));

        // Code the run never read still stops the push.
        $this->write('src/Unread.php', "<?php\n");
        $this->write('var/ai-work/tasks/tk-b.json', '{"status": "done"}' . "\n");
        $this->git('add -A && %s commit -q -m more');
        $sha = trim((string) shell_exec('git -C ' . escapeshellarg($this->root) . ' rev-parse HEAD'));

        self::assertNull((new ReceiptForCommit($this->root))->find('', $sha));
    }

    #[Test]
    public function a_ref_and_a_tasks_commits_leave_the_backlog_out_too(): void
    {
        $this->git('add -A && %s commit -q -m "the task" -m "Task: tk-a"');

        self::assertSame(self::CODE, self::paths((new GitRefChanges($this->root))->changes('HEAD~1')));
        self::assertSame(self::CODE, self::paths((new TaskCommitFiles($this->root))->entries('tk-a')));
    }

    #[Test]
    public function a_run_over_named_files_does_not_list_the_backlog_as_left_out(): void
    {
        self::assertSame(['src/B.php', 'src/C.php', 'src/D.php', 'tests/ATest.php'], self::paths(UnselectedChanges::beside($this->root, ['src/A.php'])));
    }

    #[Test]
    public function a_backlog_file_named_with_files_stays_as_asked(): void
    {
        self::assertSame(
            ['path' => 'var/ai-work/tasks/tk-a.json', 'status' => ChangedFile::STATUS_MODIFIED],
            (new ExplicitFiles($this->root))->resolve('var/ai-work/tasks/tk-a.json'),
        );
    }

    /** Only the project's own backlog: a folder of that name deeper in the tree is somebody's files. */
    #[Test]
    public function only_the_projects_own_backlog_is_left_out(): void
    {
        $this->write('src/var/ai-work/Thing.php', "<?php\n");

        self::assertContains('src/var/ai-work/Thing.php', $this->collected());
    }

    /** @return list<string> what a bare `ai:verify` collects, sorted */
    private function collected(): array
    {
        return self::paths((new DirtyWorkspaceScanner($this->root))->changedFiles());
    }

    /**
     * @param list<array{path: string, status: string}> $entries
     * @return list<string>
     */
    private static function paths(array $entries): array
    {
        $paths = array_column($entries, 'path');
        sort($paths);

        return $paths;
    }

    /**
     * The receipt of a passing `ai:verify --trace=tk-a` over these files, written as the command writes it.
     *
     * @param list<string> $paths
     */
    private function passingRunOver(array $paths): string
    {
        $receipts = new VerifyReceipts($this->root);
        $envelope = $receipts->attach([
            'generated_at' => self::NOW,
            'verdict' => 'pass',
            'changed_files' => array_map(static fn (string $path): array => ['path' => $path], $paths),
            'results' => [['id' => 'phpunit:tests/ATest.php', 'type' => 'phpunit', 'status' => 'pass', 'exit_code' => 0]],
        ], [], $receipts->fingerprintOf($paths), 'tk-a');
        $id = is_array($envelope['receipt'] ?? null) ? ($envelope['receipt']['id'] ?? null) : null;
        self::assertIsString($id, 'the receipt was not written');

        return $id;
    }

    private function write(string $path, string $content): void
    {
        if (!is_dir(dirname($this->root . '/' . $path))) {
            mkdir(dirname($this->root . '/' . $path), 0o755, true);
        }
        file_put_contents($this->root . '/' . $path, $content);
    }

    private function git(string $commands): void
    {
        $git = 'git -C ' . escapeshellarg($this->root) . ' -c user.email=t@example.invalid -c user.name=t';
        exec($git . ' ' . str_replace('%s', $git, $commands), $out, $code);
        self::assertSame(0, $code, $commands);
    }
}
