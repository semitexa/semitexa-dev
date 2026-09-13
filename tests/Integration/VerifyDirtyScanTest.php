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
        self::assertSame(ChangedFile::STATUS_RENAMED, $this->mapped('R '), 'the planner asks about the name it had');
        self::assertSame(ChangedFile::STATUS_RENAMED, $this->mapped('RM'), 'renamed and then edited is still renamed');
        self::assertSame(ChangedFile::STATUS_ADDED, $this->mapped('C '), 'a copy leaves the original in place, so it is simply new');
        self::assertSame(ChangedFile::STATUS_DELETED, $this->mapped(' D'));
        self::assertSame(ChangedFile::STATUS_DELETED, $this->mapped('D '));
        self::assertSame(ChangedFile::STATUS_DELETED, $this->mapped('RD'), 'renamed in the index, then removed: the new path is not there');
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

    /**
     * A rename is the new name PLUS the name it had. `--git-ref` has always
     * carried both, because the broken-FQCN guard resolves the OLD class name
     * from `originalPath` and asks who still calls it; a rename arriving as a
     * plain modification loses exactly that question. `--dirty` dropped it and
     * mapped the entry to `M`. Raised in review of dev#84.
     */
    #[Test]
    public function a_rename_reports_the_new_name_and_the_one_it_had(): void
    {
        $repo = $this->root . '/packages/semitexa-one';
        file_put_contents($repo . '/before.txt', "content\n");
        $this->initRepo($repo);

        $q = escapeshellarg($repo);
        exec("git -C {$q} -c safe.directory={$q} mv before.txt after.txt 2>&1");

        $entries = $this->statusOf($repo);
        $paths = array_column($entries, 'path');

        self::assertContains('after.txt', $paths);
        self::assertNotContains('before.txt -> after.txt', $paths, 'the arrow is not part of a path');
        self::assertNotContains('before.txt', $paths, 'the old name is not a file to verify');

        $rename = null;
        foreach ($entries as $entry) {
            if ($entry['path'] === 'after.txt') {
                $rename = $entry;
            }
        }

        self::assertSame(ChangedFile::STATUS_RENAMED, $rename['status'] ?? null);
        self::assertSame('before.txt', $rename['originalPath'] ?? null);
    }

    /** And the old name is workspace-relative, like the new one. */
    #[Test]
    public function the_original_path_is_prefixed_with_its_repository_too(): void
    {
        $repo = $this->root . '/packages/semitexa-one';
        file_put_contents($repo . '/before.txt', "content\n");
        $this->initRepo($repo);

        $q = escapeshellarg($repo);
        exec("git -C {$q} -c safe.directory={$q} mv before.txt after.txt 2>&1");

        $rename = null;
        foreach ($this->scanner()->changedFiles() as $entry) {
            if (($entry['status'] ?? '') === ChangedFile::STATUS_RENAMED) {
                $rename = $entry;
            }
        }

        self::assertSame('packages/semitexa-one/after.txt', $rename['path'] ?? null);
        self::assertSame('packages/semitexa-one/before.txt', $rename['originalPath'] ?? null);
    }

    /**
     * The line format QUOTES any name carrying a space, a quote, a backslash
     * or a non-ASCII byte, and escapes the contents: `"src/a b.php"`, and an
     * accented letter as `\303\251`. Stripping the outer quotes left a string
     * naming no file on disk, which the planner then could not classify or
     * lint. `-z` emits each path verbatim.
     */
    #[Test]
    public function a_path_git_would_have_quoted_comes_back_verbatim(): void
    {
        $repo = $this->root . '/packages/semitexa-one';
        $this->initRepo($repo);

        $names = ['a file.txt', 'ключ.txt', "quote\"d.txt"];
        foreach ($names as $name) {
            file_put_contents($repo . '/' . $name, "x\n");
        }

        $paths = array_column($this->statusOf($repo), 'path');

        foreach ($names as $name) {
            self::assertContains($name, $paths, 'the name on disk is the name to verify');
        }
    }

    /**
     * A copy is also two records, so its second one has to be consumed — read
     * as a status line of its own it produced a phantom entry whose two-letter
     * "code" was the first bytes of a path.
     */
    #[Test]
    public function a_copy_record_does_not_leak_its_source_as_a_second_entry(): void
    {
        $raw = "C  new.txt\0old.txt\0 M other.txt\0";

        self::assertSame([
            ['path' => 'new.txt', 'status' => ChangedFile::STATUS_ADDED],
            ['path' => 'other.txt', 'status' => ChangedFile::STATUS_MODIFIED],
        ], DirtyWorkspaceScanner::parsePorcelainZ($raw));
    }

    /** A rename followed by an ordinary entry: both, in order, neither merged. */
    #[Test]
    public function a_rename_record_and_the_entry_after_it_are_both_read(): void
    {
        $raw = "R  after.txt\0before.txt\0?? fresh.txt\0";

        self::assertSame([
            ['path' => 'after.txt', 'status' => ChangedFile::STATUS_RENAMED, 'originalPath' => 'before.txt'],
            ['path' => 'fresh.txt', 'status' => ChangedFile::STATUS_ADDED],
        ], DirtyWorkspaceScanner::parsePorcelainZ($raw));
    }

    #[Test]
    public function a_clean_repository_parses_to_nothing_rather_than_one_empty_entry(): void
    {
        self::assertSame([], DirtyWorkspaceScanner::parsePorcelainZ(''));
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
