<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;

/**
 * `--dirty` has one hard fact to respect: THE WORKSPACE IS NOT ONE REPOSITORY.
 *
 * Every `packages/semitexa-*` is its own git repository, the project root is
 * one only in a consumer install, and `src/` is not versioned in this workspace
 * at all. A single `git status` at the root — the obvious implementation —
 * answers for almost nothing and here fails outright.
 *
 * So the flag asks each repository, and reports which roots it could NOT ask.
 * A scan that quietly skips half the tree and says "no changes" is the
 * false-green this command exists to prevent.
 *
 * Against real git repositories rather than canned output: the parsing is the
 * part that breaks, and a fixture of strings would only test the fixture.
 */
final class VerifyDirtyScanTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
            self::markTestSkipped('needs git');
        }

        $this->root = sys_get_temp_dir() . '/semitexa-dirty-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-one', 0777, true);
        mkdir($this->root . '/packages/semitexa-two', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function initRepo(string $path): void
    {
        $q = escapeshellarg($path);
        exec("git -C {$q} init -q 2>&1");
        exec("git -C {$q} config user.email probe@example.com 2>&1");
        exec("git -C {$q} config user.name Probe 2>&1");
        exec("git -C {$q} -c safe.directory={$q} add -A 2>&1");
        exec("git -C {$q} -c safe.directory={$q} -c commit.gpgsign=false commit -q -m base --allow-empty 2>&1");
    }

    private function scanner(): DirtyWorkspaceScanner
    {
        return new DirtyWorkspaceScanner($this->root);
    }

    /** @return list<array{path: string, status: string}> */
    private function statusOf(string $repo): array
    {
        $method = new \ReflectionMethod(DirtyWorkspaceScanner::class, 'statusOf');

        return $method->invoke($this->scanner(), $repo);
    }

    private function mapped(string $code): string
    {
        return DirtyWorkspaceScanner::statusFor($code);
    }

    #[Test]
    public function every_porcelain_code_lands_on_a_status_the_planner_understands(): void
    {
        self::assertSame(ChangedFile::STATUS_ADDED, $this->mapped('??'), 'an untracked file is exactly what nobody verified yet');
        self::assertSame(ChangedFile::STATUS_ADDED, $this->mapped('A '));
        self::assertSame(ChangedFile::STATUS_MODIFIED, $this->mapped(' M'));
        self::assertSame(ChangedFile::STATUS_MODIFIED, $this->mapped('M '));
        self::assertSame(ChangedFile::STATUS_MODIFIED, $this->mapped('MM'));
        self::assertSame(ChangedFile::STATUS_MODIFIED, $this->mapped('R '), 'a rename is the new file, modified');
        self::assertSame(ChangedFile::STATUS_DELETED, $this->mapped(' D'));
        self::assertSame(ChangedFile::STATUS_DELETED, $this->mapped('D '));
    }

    #[Test]
    public function a_modified_an_untracked_and_a_deleted_file_all_come_back(): void
    {
        $repo = $this->root . '/packages/semitexa-one';
        file_put_contents($repo . '/kept.txt', "one\n");
        file_put_contents($repo . '/gone.txt', "two\n");
        $this->initRepo($repo);

        file_put_contents($repo . '/kept.txt', "one changed\n");
        unlink($repo . '/gone.txt');
        file_put_contents($repo . '/fresh.txt', "three\n");

        $byPath = [];
        foreach ($this->statusOf($repo) as $entry) {
            $byPath[$entry['path']] = $entry['status'];
        }

        self::assertSame(ChangedFile::STATUS_MODIFIED, $byPath['kept.txt'] ?? null);
        self::assertSame(ChangedFile::STATUS_DELETED, $byPath['gone.txt'] ?? null);
        self::assertSame(ChangedFile::STATUS_ADDED, $byPath['fresh.txt'] ?? null, 'an untracked file must be verified, not ignored');
    }

    /** `R  old -> new` — the NEW name is the one to verify. */
    #[Test]
    public function a_rename_reports_the_new_name(): void
    {
        $repo = $this->root . '/packages/semitexa-one';
        file_put_contents($repo . '/before.txt', "content\n");
        $this->initRepo($repo);

        $q = escapeshellarg($repo);
        exec("git -C {$q} -c safe.directory={$q} mv before.txt after.txt 2>&1");

        $paths = array_column($this->statusOf($repo), 'path');

        self::assertContains('after.txt', $paths);
        self::assertNotContains('before.txt -> after.txt', $paths, 'the arrow is not part of a path');
    }

    /**
     * `.git` is a DIRECTORY in an ordinary clone and a FILE in a linked
     * worktree. Testing only for the directory skipped every worktree
     * checkout silently — empty answer, exit 0, "nothing to verify" — which is
     * the exact false green the scan report exists to prevent. Raised in
     * review of dev#84.
     */
    #[Test]
    public function a_linked_worktree_is_a_repository_too(): void
    {
        $repo = $this->root . '/packages/semitexa-one';
        file_put_contents($repo . '/kept.txt', "one\n");
        $this->initRepo($repo);

        $tree = $this->root . '/packages/semitexa-two';
        $q = escapeshellarg($repo);
        rmdir($tree);
        exec("git -C {$q} -c safe.directory={$q} worktree add -q " . escapeshellarg($tree) . " -b wt 2>&1", $out, $code);
        if ($code !== 0 || !is_file($tree . '/.git')) {
            self::markTestSkipped('git worktree unavailable here: ' . implode(' / ', $out));
        }

        file_put_contents($tree . '/fresh.txt', "two\n");

        $report = $this->scanner()->report();
        self::assertContains('packages/semitexa-two', $report['scanned'], 'a worktree is a repository');
        self::assertNotContains('packages/semitexa-two', $report['unscannable']);

        $paths = array_column($this->scanner()->changedFiles(), 'path');
        self::assertContains('packages/semitexa-two/fresh.txt', $paths, 'its change must not go unseen');
    }

    /**
     * The half that keeps the answer honest: a package directory that is not a
     * repository is NAMED, so silence is never mistaken for cleanliness.
     */
    #[Test]
    public function a_root_that_cannot_be_asked_is_named_rather_than_skipped(): void
    {
        $this->initRepo($this->root . '/packages/semitexa-one');
        // semitexa-two is deliberately left without a repository.

        $report = $this->scanner()->report();

        self::assertContains('packages/semitexa-one', $report['scanned']);
        self::assertContains('packages/semitexa-two', $report['unscannable']);
        self::assertContains('(project root — not a git repository)', $report['unscannable']);
    }

}
