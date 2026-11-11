<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\State;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\State\UnshippedCommits;

/**
 * A framework commit nobody pushed is named on the next cold start
 * (tk-framework-commit-nobody-ships): core and docs fixes made from a project
 * session sat unpushed for 25 hours.
 */
final class UnshippedCommitsTest extends TestCase
{
    private string $root;

    /** Variables that would aim git at another repository; put back after (review, dev#227). */
    private const GIT_LOCATION = ['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_PREFIX'];

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        foreach (self::GIT_LOCATION as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
        $this->root = sys_get_temp_dir() . '/semitexa-unshipped-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/packages', 0755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    #[Test]
    public function a_git_dir_from_the_caller_does_not_aim_every_query_at_one_repository(): void
    {
        $this->package('semitexa-core', ahead: 1);
        $this->package('semitexa-docs', ahead: 0);
        // A hook exports GIT_DIR; without scrubbing, every package reads as core.
        putenv('GIT_DIR=' . $this->root . '/packages/semitexa-core/.git');

        self::assertSame(['packages/semitexa-core'], array_column(UnshippedCommits::in($this->root), 'repo'));
    }

    #[Test]
    public function a_package_with_a_commit_its_upstream_lacks_is_named(): void
    {
        $this->package('semitexa-core', ahead: 2);
        $this->package('semitexa-docs', ahead: 0);

        self::assertSame([
            ['repo' => 'packages/semitexa-core', 'branch' => 'develop', 'ahead' => 2, 'last' => 'local 2'],
        ], UnshippedCommits::in($this->root));
    }

    #[Test]
    public function a_project_without_packages_has_nothing_to_name(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root . '/packages'));

        self::assertSame([], UnshippedCommits::in($this->root));
    }

    /** A package repository on develop tracking a bare remote, $ahead local commits past it. */
    private function package(string $name, int $ahead): void
    {
        $remote = $this->root . "/remotes/{$name}.git";
        $repo = $this->root . "/packages/{$name}";
        $git = static fn (string $dir, string $args): string => 'git -C ' . escapeshellarg($dir) . ' -c user.name=t -c user.email=t@example.test -c commit.gpgsign=false ' . $args . ' 2>&1';
        $run = static function (string $command): void {
            exec($command, $out, $status);
            self::assertSame(0, $status, $command . "\n" . implode("\n", $out));
        };

        mkdir($repo, 0755, true);
        $run('git init -q --bare ' . escapeshellarg($remote) . ' 2>&1');
        $run($git($repo, 'init -q -b develop'));
        $run($git($repo, 'commit -q --allow-empty -m shipped'));
        $run($git($repo, 'remote add origin ' . escapeshellarg($remote)));
        $run($git($repo, 'push -q -u origin develop'));
        for ($i = 1; $i <= $ahead; $i++) {
            $run($git($repo, "commit -q --allow-empty -m 'local {$i}'"));
        }
    }
}
