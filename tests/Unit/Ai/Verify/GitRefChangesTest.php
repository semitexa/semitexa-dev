<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\GitRefChanges;
use Semitexa\Dev\Application\Service\Ai\Verify\TestIntegrity\WorkspaceRevisions;

/**
 * The workspace root is no repository; every package is. `--git-ref` used to
 * run git at the root and fail outright ("Not a git repository").
 */
final class GitRefChangesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/git-ref-changes-' . bin2hex(random_bytes(4));
        foreach (['semitexa-a', 'semitexa-b'] as $package) {
            $repo = $this->root . '/packages/' . $package;
            mkdir($repo . '/tests', 0777, true);
            file_put_contents($repo . '/tests/ATest.php', "<?php // v1\n");
            $this->git($repo, 'init -q');
            $this->git($repo, 'add -A');
            $this->git($repo, 'commit -qm one');
        }
        file_put_contents($this->root . '/packages/semitexa-a/tests/ATest.php', "<?php // v2\n");
        $this->git($this->root . '/packages/semitexa-a', 'commit -qam two');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function every_package_repository_is_diffed_and_its_paths_are_prefixed(): void
    {
        file_put_contents($this->root . '/packages/semitexa-b/tests/ATest.php', "<?php // uncommitted\n");

        self::assertSame(
            ["M\tpackages/semitexa-b/tests/ATest.php"],
            (new GitRefChanges($this->root))->nameStatus('HEAD'),
        );
    }

    #[Test]
    public function a_ref_missing_in_one_repository_fails_instead_of_leaving_it_out(): void
    {
        // semitexa-b has one commit: HEAD~1 does not exist there.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("git diff against 'HEAD~1' failed in packages/semitexa-b");

        (new GitRefChanges($this->root))->nameStatus('HEAD~1');
    }

    #[Test]
    public function a_ref_that_looks_like_an_option_is_refused(): void
    {
        $this->expectExceptionObject(new \RuntimeException("'--output=/tmp/x' is not a git ref"));

        (new GitRefChanges($this->root))->nameStatus('--output=/tmp/x');
    }

    #[Test]
    public function revisions_read_the_base_ref_and_fail_where_it_does_not_resolve(): void
    {
        self::assertSame("<?php // v1\n", (new WorkspaceRevisions($this->root, 'HEAD~1'))->committed('packages/semitexa-a/tests/ATest.php'));
        self::assertSame("<?php // v2\n", (new WorkspaceRevisions($this->root))->committed('packages/semitexa-a/tests/ATest.php'));

        $this->expectExceptionObject(new \RuntimeException('HEAD~1 does not resolve to a commit in ' . $this->root . '/packages/semitexa-b'));
        (new WorkspaceRevisions($this->root, 'HEAD~1'))->committed('packages/semitexa-b/tests/ATest.php');
    }

    #[Test]
    public function a_project_with_no_repository_is_an_error_not_an_empty_change(): void
    {
        $empty = sys_get_temp_dir() . '/git-ref-none-' . bin2hex(random_bytes(4));
        mkdir($empty);
        try {
            $this->expectExceptionObject(new \RuntimeException("git diff against 'HEAD' failed: no git repository in {$empty}"));
            (new GitRefChanges($empty))->nameStatus('HEAD');
        } finally {
            rmdir($empty);
        }
    }

    private function git(string $repo, string $arguments): void
    {
        exec('git -C ' . escapeshellarg($repo) . ' -c user.name=t -c user.email=t@t ' . $arguments . ' 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
