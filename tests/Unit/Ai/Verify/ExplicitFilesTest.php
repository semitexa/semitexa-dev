<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ExplicitFiles;

/**
 * A --files path that does not exist is refused, not verified as modified
 * (tk-verify-missing-path-is-not-modified): a typo came back status M with a
 * red "file no longer exists", and the agent hunted its own mistake through
 * the receipts for a dozen steps.
 */
final class ExplicitFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-explicit-files-' . bin2hex(random_bytes(4));
        $package = $this->root . '/packages/semitexa-x';
        mkdir($package . '/src/Payload/Request', 0o755, true);
        file_put_contents($package . '/composer.json', '{"name": "semitexa/x"}');
        file_put_contents($package . '/src/Payload/Request/LoginPayload.php', "<?php\n");
        file_put_contents($package . '/src/Gone.php', "<?php\n");
        $git = 'git -C ' . escapeshellarg($package) . ' -c user.email=t@example.invalid -c user.name=t';
        exec("{$git} init -q && {$git} add -A && {$git} commit -q -m init", $out, $code);
        self::assertSame(0, $code, 'git is needed to tell a deletion from a typo');
        unlink($package . '/src/Gone.php');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_file_on_disk_is_modified_and_a_deleted_tracked_one_is_deleted(): void
    {
        $files = new ExplicitFiles($this->root);

        self::assertSame(ChangedFile::STATUS_MODIFIED, $files->resolve('packages/semitexa-x/src/Payload/Request/LoginPayload.php')['status']);
        self::assertSame(ChangedFile::STATUS_DELETED, $files->resolve('packages/semitexa-x/src/Gone.php')['status']);
        self::assertSame(ChangedFile::STATUS_DELETED, $files->resolve('./packages/semitexa-x/src/Gone.php')['status'], 'written from the current directory too');
    }

    /** Review, dev#239: a dangling link is present; a glob in a typo matches nothing in the index. */
    #[Test]
    public function a_dangling_symlink_is_modified_and_a_glob_is_read_literally(): void
    {
        $link = $this->root . '/packages/semitexa-x/src/Link.php';
        symlink($this->root . '/nowhere.php', $link);
        $files = new ExplicitFiles($this->root);

        self::assertSame(ChangedFile::STATUS_MODIFIED, $files->resolve('packages/semitexa-x/src/Link.php')['status']);
        try {
            $files->resolve('packages/semitexa-x/src/G*.php');
            self::fail('a glob matched the deleted src/Gone.php in the index');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('does not exist', $e->getMessage());
        }
    }

    #[Test]
    public function a_path_neither_on_disk_nor_in_git_is_refused_with_the_file_it_probably_meant(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('packages/semitexa-x/src/Payload/LoginPayload.php does not exist, and git knows no such file. A file of that name: packages/semitexa-x/src/Payload/Request/LoginPayload.php');

        (new ExplicitFiles($this->root))->resolve('packages/semitexa-x/src/Payload/LoginPayload.php');
    }
}
