<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\CommitCoverage;

/**
 * A commit is the change a receipt vouches for only when every file it adds
 * or changes is one the run checked, with the bytes the run checked. Against
 * real repositories: `--files=A` passed, then A and B were committed.
 */
final class CommitCoverageTest extends TestCase
{
    private string $root;

    private string $repo;

    protected function setUp(): void
    {
        // git ships in the image this runs in: no skip for a runtime we never ship.
        $this->root = sys_get_temp_dir() . '/semitexa-commit-coverage-' . uniqid('', true);
        $this->repo = $this->root . '/packages/semitexa-one';
        mkdir($this->repo . '/src', 0777, true);
        $this->write('src/Kept.php', "<?php\n");
        $this->write('src/Gone.php', "<?php\n");
        $this->git('init', '-q');
        $this->commit('base');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function write(string $relative, string $contents): void
    {
        file_put_contents($this->repo . '/' . $relative, $contents);
    }

    private function git(string ...$args): string
    {
        $q = escapeshellarg($this->repo);

        return (string) shell_exec("git -C {$q} -c safe.directory={$q} -c user.email=probe@example.com -c user.name=Probe -c commit.gpgsign=false " . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
    }

    private function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '-m', $message);
    }

    /** @return array<string, ?string> what a run checked: path => sha256 */
    private static function checked(string ...$files): array
    {
        $tree = [];
        foreach ($files as $path => $contents) {
            $tree['packages/semitexa-one/' . $path] = hash('sha256', $contents);
        }

        return $tree;
    }

    #[Test]
    public function a_commit_of_exactly_what_was_checked_is_covered(): void
    {
        $this->write('src/Selected.php', "<?php // A\n");
        $this->commit('A');

        $coverage = (new CommitCoverage($this->root))->of(self::checked(...['src/Selected.php' => "<?php // A\n"]), 'HEAD');

        self::assertSame(['packages/semitexa-one'], array_keys($coverage['commits']));
        self::assertSame([[], [], []], [$coverage['unresolved'], $coverage['unchecked'], $coverage['differs']]);
    }

    #[Test]
    public function a_file_committed_beside_the_checked_one_is_named(): void
    {
        $this->write('src/Selected.php', "<?php // A\n");
        $this->write('src/Beside.php', "<?php return \$undefined;\n");
        unlink($this->repo . '/src/Gone.php');
        $this->commit('A and B');

        $coverage = (new CommitCoverage($this->root))->of(self::checked(...['src/Selected.php' => "<?php // A\n"]), 'HEAD');

        self::assertSame(['packages/semitexa-one/src/Beside.php'], $coverage['unchecked'], 'a deletion has no bytes to check and is not asked about');
        self::assertSame([], $coverage['differs']);
    }

    #[Test]
    public function a_file_committed_with_other_bytes_than_the_run_checked_is_named(): void
    {
        $this->write('src/Selected.php', "<?php // edited after the run\n");
        $this->commit('A, edited');

        $coverage = (new CommitCoverage($this->root))->of(self::checked(...['src/Selected.php' => "<?php // A\n"]), 'HEAD');

        self::assertSame(['packages/semitexa-one/src/Selected.php'], $coverage['differs']);
        self::assertSame([], $coverage['unchecked']);
    }

    #[Test]
    public function a_revision_that_names_no_commit_is_reported_not_taken_as_empty(): void
    {
        $coverage = (new CommitCoverage($this->root))->of(self::checked(...['src/Kept.php' => "<?php\n"]), 'no-such-branch');

        self::assertSame([], $coverage['commits']);
        self::assertSame(['packages/semitexa-one'], $coverage['unresolved']);
    }
}
