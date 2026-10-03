<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify\TestIntegrity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\WorkspaceRevisions;

/**
 * Null from committed() means "not in HEAD" and nothing else; every way of not
 * getting the content throws, or a weakened test passes as a new file
 * (review of dev#127).
 */
final class WorkspaceRevisionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/workspace-revisions-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages/semitexa-x/tests', 0777, true);
        mkdir($this->root . '/packages/semitexa-broken/tests', 0777, true);
        mkdir($this->root . '/loose', 0777, true);
        file_put_contents($this->root . '/packages/semitexa-x/tests/OldTest.php', "<?php // committed\n// verify:accept-test-change an old reason, ends in spaces   \n");
        $git = 'git -C ' . escapeshellarg($this->root . '/packages/semitexa-x') . ' -c user.name=t -c user.email=t@t ';
        exec($git . 'init -q && ' . $git . 'add -A && ' . $git . 'commit -qm x 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        file_put_contents($this->root . '/packages/semitexa-x/tests/NewTest.php', "<?php\n");
        // A .git that points nowhere: a repository git cannot read.
        file_put_contents($this->root . '/packages/semitexa-broken/.git', "gitdir: /nonexistent/semitexa\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function committed_content_is_read_and_a_new_file_is_null(): void
    {
        $revisions = new WorkspaceRevisions($this->root);

        // Byte for byte: exec() used to strip the trailing spaces, and the
        // unchanged old marker line then read as added (review of dev#127).
        self::assertSame("<?php // committed\n// verify:accept-test-change an old reason, ends in spaces   \n", $revisions->committed('packages/semitexa-x/tests/OldTest.php'));
        self::assertNull($revisions->committed('packages/semitexa-x/tests/NewTest.php'));
    }

    #[Test]
    public function an_unreadable_repository_throws_instead_of_reading_as_new(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cannot read the repository ' . $this->root . '/packages/semitexa-broken');

        (new WorkspaceRevisions($this->root))->committed('packages/semitexa-broken/tests/ATest.php');
    }

    #[Test]
    public function a_path_no_repository_owns_throws(): void
    {
        $this->expectExceptionObject(new \RuntimeException('no git repository owns loose/ATest.php'));

        (new WorkspaceRevisions($this->root))->committed('loose/ATest.php');
    }

    #[Test]
    public function an_unborn_branch_is_new_and_a_broken_head_is_an_error(): void
    {
        mkdir($this->root . '/packages/semitexa-fresh/tests', 0777, true);
        exec('git -C ' . escapeshellarg($this->root . '/packages/semitexa-fresh') . ' init -q');
        self::assertNull((new WorkspaceRevisions($this->root))->committed('packages/semitexa-fresh/tests/ATest.php'));

        // HEAD naming a commit that does not exist: not "new" (review of dev#127).
        file_put_contents($this->root . '/packages/semitexa-x/.git/HEAD', str_repeat('a', 40) . "\n");
        $this->expectExceptionObject(new \RuntimeException('HEAD of ' . $this->root . '/packages/semitexa-x does not resolve to a commit'));
        (new WorkspaceRevisions($this->root))->committed('packages/semitexa-x/tests/OldTest.php');
    }

    #[Test]
    public function a_branch_ref_holding_a_missing_commit_is_an_error_not_unborn(): void
    {
        // HEAD -> refs/heads/<branch> -> an object that does not exist: the
        // ref is there and broken, not absent (review of dev#127).
        $repository = $this->root . '/packages/semitexa-x';
        exec('git -C ' . escapeshellarg($repository) . ' symbolic-ref HEAD', $out);
        file_put_contents($repository . '/.git/' . trim($out[0]), str_repeat('a', 40) . "\n");

        $this->expectExceptionObject(new \RuntimeException('HEAD of ' . $repository . ' does not resolve to a commit'));
        (new WorkspaceRevisions($this->root))->committed('packages/semitexa-x/tests/OldTest.php');
    }
}
