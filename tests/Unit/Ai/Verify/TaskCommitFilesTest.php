<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\TaskCommitFiles;

/**
 * A committed task is proven from its own commits (tk-committed-task-cannot-be-proven):
 * `ai:verify --trace=<task>` on a clean tree verified nothing, and the task stayed unproven.
 */
final class TaskCommitFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-task-commits-' . bin2hex(random_bytes(4));
        $package = $this->root . '/packages/semitexa-x';
        mkdir($package . '/src', 0o755, true);
        file_put_contents($package . '/composer.json', '{"name": "semitexa/x"}');
        $git = 'git -C ' . escapeshellarg($package) . ' -c user.email=t@example.invalid -c user.name=t';
        file_put_contents($package . '/src/Kept.php', "<?php\n");
        file_put_contents($package . '/src/Other.php', "<?php\n");
        exec("{$git} init -q && {$git} add -A && {$git} commit -q -m init", $out, $code);
        self::assertSame(0, $code, 'git is needed to read the commits of a task');
        file_put_contents($package . '/src/Kept.php', "<?php\n// task\n");
        file_put_contents($package . '/src/Gone.php', "<?php\n");
        exec("{$git} add -A && {$git} commit -q -m 'the change' -m 'Task: tk-a'");
        unlink($package . '/src/Gone.php');
        exec("{$git} commit -q -am 'drop Gone' -m 'Task: tk-a'");
        file_put_contents($package . '/src/Other.php', "<?php\n// other\n");
        exec("{$git} commit -q -am 'another task' -m 'Task: tk-b'");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_files_of_the_tasks_own_commits_and_no_others(): void
    {
        self::assertSame([
            ['path' => 'packages/semitexa-x/src/Gone.php', 'status' => ChangedFile::STATUS_DELETED],
            ['path' => 'packages/semitexa-x/src/Kept.php', 'status' => ChangedFile::STATUS_MODIFIED],
        ], (new TaskCommitFiles($this->root))->entries('tk-a'));
        self::assertSame([], (new TaskCommitFiles($this->root))->entries('tk-none'), 'a task with no commit has no files');
    }

    /** Review, dev#252: a name git would quote, a merge carrying the line, a history that cannot be read. */
    #[Test]
    public function quoted_names_merges_and_an_unreadable_history(): void
    {
        $package = $this->root . '/packages/semitexa-x';
        $git = 'git -C ' . escapeshellarg($package) . ' -c user.email=t@example.invalid -c user.name=t';
        file_put_contents($package . '/src/é.php', "<?php\n");
        exec("{$git} add -A && {$git} commit -q -m 'accented' -m 'Task: tk-c'");
        exec("{$git} checkout -q -b side && " . 'echo "<?php" > ' . escapeshellarg($package . '/src/Side.php') . " && {$git} add -A && {$git} commit -q -m side && {$git} checkout -q - && {$git} merge -q --no-ff side -m 'merge side' -m 'Task: tk-m'");

        $files = new TaskCommitFiles($this->root);
        self::assertSame([['path' => 'packages/semitexa-x/src/é.php', 'status' => ChangedFile::STATUS_MODIFIED]], $files->entries('tk-c'));
        self::assertSame([['path' => 'packages/semitexa-x/src/Side.php', 'status' => ChangedFile::STATUS_MODIFIED]], $files->entries('tk-m'));

        file_put_contents($package . '/.git/HEAD', 'not a ref');
        $this->expectException(\RuntimeException::class);
        $files->entries('tk-a');
    }

    /** Review, dev#252: a rename keeps its old path in scope; a broken branch fails, with git's words. */
    #[Test]
    public function a_rename_names_both_paths_and_a_broken_ref_is_an_error(): void
    {
        $package = $this->root . '/packages/semitexa-x';
        $git = 'git -C ' . escapeshellarg($package) . ' -c user.email=t@example.invalid -c user.name=t';
        exec("{$git} mv src/Other.php src/Renamed.php && {$git} commit -q -m 'rename' -m 'Task: tk-r'");

        $files = new TaskCommitFiles($this->root);
        self::assertSame([
            ['path' => 'packages/semitexa-x/src/Other.php', 'status' => ChangedFile::STATUS_DELETED],
            ['path' => 'packages/semitexa-x/src/Renamed.php', 'status' => ChangedFile::STATUS_MODIFIED],
        ], $files->entries('tk-r'));

        file_put_contents($package . '/.git/refs/heads/broken', str_repeat('1', 40) . "\n");
        try {
            $files->entries('tk-r');
            self::fail('a branch pointing at a missing object is not an empty history');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('git rev-list exited', $e->getMessage());
            self::assertMatchesRegularExpression('/(bad object|fatal|error)/i', $e->getMessage(), "git's own words are in the message");
        }
    }
}
