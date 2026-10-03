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
        file_put_contents($this->root . '/packages/semitexa-x/tests/OldTest.php', "<?php // committed\n");
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

        self::assertSame('<?php // committed', $revisions->committed('packages/semitexa-x/tests/OldTest.php'));
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
}
