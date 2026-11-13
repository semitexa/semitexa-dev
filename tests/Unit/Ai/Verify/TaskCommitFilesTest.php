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
}
