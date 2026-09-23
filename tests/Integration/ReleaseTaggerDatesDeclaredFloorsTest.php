<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The tagger dates a declared floor itself, into the tree its tag is cut from.
 *
 * Dating used to be a manual step between preflight and finalize, and the
 * tagger resets to origin/master, so a floor written but not committed was not
 * in the release. The resolver that did commit put the floor on master only:
 * measured 2026-09-22, semitexa-ssr and semitexa-webhooks were tagged with dated
 * floors while develop still read `next` and the stale constraint.
 *
 * Runs the shipped bump-packages.php in library mode, in a child process —
 * every refusal in it is an exit(), and those are exactly what is under test.
 */
final class ReleaseTaggerDatesDeclaredFloorsTest extends TestCase
{
    private const VERSION = '2026.09.23.1000';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-tagger-floors-' . uniqid('', true);
        mkdir($this->root . '/packages', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function git(string $dir, string $command): string
    {
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($dir), $command), $output, $exit);
        self::assertSame(0, $exit, "git {$command} failed: " . implode("\n", $output));

        return implode("\n", $output);
    }

    private function dir(string $package): string
    {
        return $this->root . '/packages/' . $package;
    }

    /**
     * A package the way the release clone holds it: master and develop on an
     * origin, develop equal to master (the release PRs are merged), checked out
     * on develop so the tagger has to move it.
     *
     * @param array<string, mixed> $composer
     */
    private function package(string $package, array $composer): void
    {
        $dir = $this->dir($package);
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/composer.json', json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        $this->git($dir, 'init -q -b master');
        $this->git($dir, 'config user.email test@example.com');
        $this->git($dir, 'config user.name Test');
        $this->git($dir, 'add -A');
        $this->git($dir, 'commit -q -m state');

        $origin = $this->root . '/origin-' . $package . '.git';
        exec(sprintf('git init -q --bare %s', escapeshellarg($origin)));
        $this->git($dir, 'remote add origin ' . escapeshellarg($origin));
        $this->git($dir, 'push -q origin master');
        $this->git($dir, 'checkout -q -b develop');
        $this->git($dir, 'push -q origin develop');
    }

    private function declaring(): void
    {
        $this->package('semitexa-ssr', [
            'name' => 'semitexa/ssr',
            'require' => ['semitexa/core' => '*'],
            'extra' => ['semitexa' => ['floors' => ['semitexa/core' => 'next']]],
        ]);
        $this->package('semitexa-core', ['name' => 'semitexa/core']);
    }

    /**
     * @param list<string> $packages the candidates of this run, by directory
     * @return array{exit: int, output: string}
     */
    private function release(array $packages, bool $noPush, bool $tag = true): array
    {
        $candidates = array_map(fn (string $package): array => [
            'name' => 'semitexa/' . substr($package, strlen('semitexa-')),
            'version' => self::VERSION,
            'package_dir' => $this->dir($package),
            'composer_path' => $this->dir($package) . '/composer.json',
            'info' => [],
        ], $packages);

        $input = $this->root . '/input.json';
        file_put_contents($input, json_encode(['candidates' => $candidates, 'noPush' => $noPush, 'tag' => $tag]));

        $driver = $this->root . '/driver.php';
        file_put_contents($driver, <<<'PHP'
            <?php
            define('BUMP_PACKAGES_LIBRARY_MODE', true);
            require $argv[1];
            $in = json_decode((string) file_get_contents($argv[2]), true);
            $candidates = dateDeclaredFloors($in['candidates'], '2026.09.23.1000', $in['noPush']);
            if ($in['tag']) {
                foreach ($candidates as $candidate) {
                    releaseMasterHead($candidate, '2026.09.23.1000', $in['noPush']);
                }
            }
            PHP);

        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/bump-packages.php';
        // SEMITEXA_DEV_ROOT pinned inside the fixture: the tagger syncs the
        // matching authoring checkout after a floor commit, and an inherited
        // value would point it at the real workspace.
        exec(sprintf(
            'SEMITEXA_DEV_ROOT=%s php %s %s %s 2>&1',
            escapeshellarg($this->root . '/authoring'),
            escapeshellarg($driver),
            escapeshellarg($script),
            escapeshellarg($input),
        ), $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /** @return array<mixed> */
    private function manifestAt(string $repo, string $revision): array
    {
        $json = json_decode($this->git($repo, 'show ' . escapeshellarg($revision . ':composer.json')), true);
        self::assertIsArray($json);

        return $json;
    }

    /** THE POINT: the tag itself carries the dated floor, with nobody running a step by hand. */
    #[Test]
    public function the_tag_carries_the_floor_the_release_dated(): void
    {
        $this->declaring();

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertSame(0, $result['exit'], $result['output']);
        $tagged = $this->manifestAt($this->root . '/origin-semitexa-ssr.git', self::VERSION);
        self::assertSame('>=2026.09.23.1000 || dev-master', $tagged['require']['semitexa/core']);
        self::assertSame(self::VERSION, $tagged['extra']['semitexa']['floors']['semitexa/core']);
    }

    /** And develop has it too — the half the old master-only commit left behind. */
    #[Test]
    public function develop_carries_the_floor_as_well_as_master(): void
    {
        $this->declaring();

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertSame(0, $result['exit'], $result['output']);
        $origin = $this->root . '/origin-semitexa-ssr.git';
        self::assertSame(
            $this->git($origin, 'rev-parse master'),
            $this->git($origin, 'rev-parse develop'),
            'the floor commit is made on develop and master is fast-forwarded to it',
        );
        self::assertSame(
            '>=2026.09.23.1000 || dev-master',
            $this->manifestAt($origin, 'develop')['require']['semitexa/core'],
        );
    }

    /**
     * --no-push is how the release is rehearsed, so it must tag what a real run
     * would: the floor commit exists only locally, and resetting to origin/master
     * before tagging would take it back out.
     */
    #[Test]
    public function a_rehearsal_tags_the_local_floor_commit_and_publishes_nothing(): void
    {
        $this->declaring();

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: true);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(
            '>=2026.09.23.1000 || dev-master',
            $this->manifestAt($this->dir('semitexa-ssr'), self::VERSION)['require']['semitexa/core'],
        );
        self::assertSame(
            'next',
            $this->manifestAt($this->root . '/origin-semitexa-ssr.git', 'master')['extra']['semitexa']['floors']['semitexa/core'],
            'nothing reaches origin in a rehearsal',
        );
    }

    /**
     * A run filtered to one package cannot date a floor against a provider it is
     * not tagging — and refuses before committing anything, so no tree is left
     * half-dated.
     */
    #[Test]
    public function a_provider_outside_this_run_is_refused_before_any_commit(): void
    {
        $this->declaring();
        $before = $this->git($this->root . '/origin-semitexa-ssr.git', 'rev-parse master');

        $result = $this->release(['semitexa-ssr'], noPush: false);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('semitexa/core is not being tagged', $result['output']);
        self::assertSame($before, $this->git($this->root . '/origin-semitexa-ssr.git', 'rev-parse master'));
        self::assertSame('', $this->git($this->dir('semitexa-ssr'), 'tag --list'));
    }

    /**
     * Develop carrying work master does not have cannot be fast-forwarded; a
     * merge here would publish unreviewed commits under a message about floors.
     */
    #[Test]
    public function develop_ahead_of_master_is_refused_without_a_tag(): void
    {
        $this->declaring();
        $ssr = $this->dir('semitexa-ssr');
        file_put_contents($ssr . '/README.md', "unreviewed\n");
        $this->git($ssr, 'add README.md');
        $this->git($ssr, 'commit -q -m unreviewed');
        $this->git($ssr, 'push -q origin develop');

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('develop contains commits that are not in origin/master', $result['output']);
        self::assertSame('', $this->git($ssr, 'tag --list'));
        self::assertSame(
            'next',
            $this->manifestAt($this->root . '/origin-semitexa-ssr.git', 'master')['extra']['semitexa']['floors']['semitexa/core'],
        );
    }

    /**
     * Every refusal comes before the first commit. With two packages to date
     * and the SECOND one refused, the first must not have pushed a floor dated
     * at a release that is then never cut.
     */
    #[Test]
    public function a_refusal_on_any_package_comes_before_the_first_commit(): void
    {
        $this->declaring();
        $this->package('semitexa-os', [
            'name' => 'semitexa/os',
            'require' => ['semitexa/core' => '*'],
            'extra' => ['semitexa' => ['floors' => ['semitexa/core' => 'next']]],
        ]);
        $os = $this->dir('semitexa-os');
        file_put_contents($os . '/README.md', "unreviewed\n");
        $this->git($os, 'add README.md');
        $this->git($os, 'commit -q -m unreviewed');
        $this->git($os, 'push -q origin develop');

        $result = $this->release(['semitexa-ssr', 'semitexa-os', 'semitexa-core'], noPush: false);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('develop contains commits that are not in origin/master', $result['output']);
        $origin = $this->root . '/origin-semitexa-ssr.git';
        self::assertSame('next', $this->manifestAt($origin, 'master')['extra']['semitexa']['floors']['semitexa/core']);
        self::assertSame('next', $this->manifestAt($origin, 'develop')['extra']['semitexa']['floors']['semitexa/core']);
    }

    /**
     * The scan's origin/master can be stale by the time floors are read. A
     * declaration that reached master after it must still be dated — and the
     * tag must be cut from that master, not the stale one.
     */
    #[Test]
    public function a_declaration_that_reached_master_after_the_scan_is_dated(): void
    {
        $this->package('semitexa-ssr', ['name' => 'semitexa/ssr', 'require' => ['semitexa/core' => '*']]);
        $this->package('semitexa-core', ['name' => 'semitexa/core']);

        $other = $this->root . '/other-ssr';
        exec(sprintf('git clone -q %s %s 2>&1', escapeshellarg($this->root . '/origin-semitexa-ssr.git'), escapeshellarg($other)));
        $this->git($other, 'config user.email test@example.com');
        $this->git($other, 'config user.name Test');
        file_put_contents($other . '/composer.json', json_encode([
            'name' => 'semitexa/ssr',
            'require' => ['semitexa/core' => '*'],
            'extra' => ['semitexa' => ['floors' => ['semitexa/core' => 'next']]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        $this->git($other, 'commit -q -am declare');
        $this->git($other, 'push -q origin HEAD:master HEAD:develop');

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(
            '>=2026.09.23.1000 || dev-master',
            $this->manifestAt($this->root . '/origin-semitexa-ssr.git', self::VERSION)['require']['semitexa/core'],
        );
    }

    /**
     * develop and master go to the remote together or not at all. A push that
     * moved develop and then failed on master left the release commit on
     * origin/develop only, and the next run refused the package as
     * "develop ahead of master" instead of retrying.
     */
    #[Test]
    public function a_rejected_master_push_leaves_develop_unmoved_too(): void
    {
        $this->declaring();
        $origin = $this->root . '/origin-semitexa-ssr.git';
        $developBefore = $this->git($origin, 'rev-parse develop');
        $masterBefore = $this->git($origin, 'rev-parse master');
        // Server side, and master only. A client-side pre-push hook aborts the
        // whole push before any ref is sent, --atomic or not, so it could not
        // tell the two apart; an update hook rejects one ref and lets the rest
        // through unless the push is atomic.
        $hook = $origin . '/hooks/update';
        file_put_contents($hook, "#!/bin/sh\n[ \"\$1\" = refs/heads/master ] && exit 1\nexit 0\n");
        chmod($hook, 0755);

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertNotSame(0, $result['exit'], $result['output']);
        self::assertSame($masterBefore, $this->git($origin, 'rev-parse master'));
        self::assertSame($developBefore, $this->git($origin, 'rev-parse develop'), 'develop moved without master');
    }

    /**
     * The floor commit reaches the authoring checkout too. Without the sync a
     * clean checkout kept `"next"`, and its next push proposed the floor again.
     */
    #[Test]
    public function the_authoring_checkout_receives_the_dated_floor(): void
    {
        $this->declaring();
        $authoring = $this->root . '/authoring/packages/semitexa-ssr';
        mkdir(dirname($authoring), 0777, true);
        exec(sprintf('git clone -q -b develop %s %s 2>&1', escapeshellarg($this->root . '/origin-semitexa-ssr.git'), escapeshellarg($authoring)));

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertSame(0, $result['exit'], $result['output']);
        $origin = $this->root . '/origin-semitexa-ssr.git';
        self::assertSame($this->git($origin, 'rev-parse develop'), $this->git($authoring, 'rev-parse develop'));
        self::assertSame(
            '>=2026.09.23.1000 || dev-master',
            json_decode((string) file_get_contents($authoring . '/composer.json'), true)['require']['semitexa/core'],
        );
    }

    /**
     * The sync fast-forwards or leaves the branch alone. An authoring checkout
     * holding an unpushed commit used to be reset to origin, and the commit was
     * gone from the branch although the release succeeded.
     */
    #[Test]
    public function an_unpushed_authoring_commit_survives_the_sync(): void
    {
        $this->declaring();
        $authoring = $this->root . '/authoring/packages/semitexa-ssr';
        mkdir(dirname($authoring), 0777, true);
        exec(sprintf('git clone -q -b develop %s %s 2>&1', escapeshellarg($this->root . '/origin-semitexa-ssr.git'), escapeshellarg($authoring)));
        $this->git($authoring, 'config user.email test@example.com');
        $this->git($authoring, 'config user.name Test');
        file_put_contents($authoring . '/WIP.md', "unpushed\n");
        $this->git($authoring, 'add WIP.md');
        $this->git($authoring, 'commit -q -m wip');
        $wip = $this->git($authoring, 'rev-parse HEAD');

        $result = $this->release(['semitexa-ssr', 'semitexa-core'], noPush: false);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame($wip, $this->git($authoring, 'rev-parse develop'));
        self::assertStringContainsString('left it as it is', $result['output']);
    }

    /** No declaration, no commit: the tag lands on master exactly as it was. */
    #[Test]
    public function a_package_without_a_declaration_is_tagged_as_it_stands(): void
    {
        $this->package('semitexa-core', ['name' => 'semitexa/core']);
        $origin = $this->root . '/origin-semitexa-core.git';
        $before = $this->git($origin, 'rev-parse master');

        $result = $this->release(['semitexa-core'], noPush: false);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame($before, $this->git($origin, 'rev-parse master'));
        self::assertSame($before, $this->git($origin, 'rev-list -n 1 ' . self::VERSION));
    }
}
