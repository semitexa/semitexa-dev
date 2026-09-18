<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An author declares WHICH dependency needs a floor; the release writes WHEN.
 *
 * A floor is a guess about a date until the tag exists, and the guess goes stale
 * the moment a release slips — measured 2026-09-16, when semitexa/os floored
 * semitexa/prompt at the day the author expected the cut, review ran a day past
 * it, and preflight died on "that tag is not in semitexa-prompt".
 *
 * Run against the shipped script through its RELEASE_ROOT seam, so this tests
 * what the release actually executes rather than a copy of its rule.
 */
final class FloorDeclarationResolutionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-floors-' . uniqid('', true);
        mkdir($this->root . '/packages/semitexa-ssr', 0777, true);
        mkdir($this->root . '/packages/semitexa-core', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function git(string $dir, string $command): void
    {
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($dir), $command), $output, $exit);
        self::assertSame(0, $exit, "git {$command} failed: " . implode("\n", (array) $output));
    }

    /**
     * A package checkout, released or not.
     *
     * The release set is DERIVED from "master HEAD carries no release tag", the
     * same rule the tagger uses, so a fixture of plain directories would test a
     * different script than the one that runs.
     */
    private function checkout(string $name, bool $alreadyReleased): void
    {
        $dir = $this->root . '/packages/' . $name;
        $this->git($dir, 'init -q -b master');
        $this->git($dir, 'config user.email test@example.com');
        $this->git($dir, 'config user.name Test');
        $this->git($dir, 'add -A');
        $this->git($dir, 'commit -q -m state');

        if ($alreadyReleased) {
            $this->git($dir, 'tag 2026.09.17.1037');
        }
    }

    /**
     * @param array<string, mixed> $composer
     * @param bool $providerIsBeingTagged false leaves semitexa/core already released
     */
    private function writePackage(array $composer, bool $providerIsBeingTagged = true): void
    {
        file_put_contents(
            $this->root . '/packages/semitexa-ssr/composer.json',
            json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        );
        file_put_contents(
            $this->root . '/packages/semitexa-core/composer.json',
            json_encode(['name' => 'semitexa/core'], JSON_PRETTY_PRINT) . PHP_EOL,
        );

        $this->checkout('semitexa-ssr', false);
        $this->checkout('semitexa-core', !$providerIsBeingTagged);
    }

    /** @return array{exit: int, output: string} */
    private function resolve(string $mode, string $version = '', string $set = ''): array
    {
        $script = dirname(__DIR__, 2) . '/resources/skills/release-readiness/scripts/release-resolve-floors.php';

        $command = sprintf(
            'RELEASE_ROOT=%s RELEASE_VERSION=%s RELEASE_SET=%s php %s %s 2>&1',
            escapeshellarg($this->root),
            escapeshellarg($version),
            escapeshellarg($set),
            escapeshellarg($script),
            $mode,
        );

        $output = [];
        $exit = 0;
        exec($command, $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }

    /** @return array<string, mixed> */
    private function readPackage(): array
    {
        $json = json_decode((string) file_get_contents($this->root . '/packages/semitexa-ssr/composer.json'), true);
        self::assertIsArray($json);

        return $json;
    }

    /** @return array<string, mixed> */
    private function declaringPackage(): array
    {
        return [
            'name' => 'semitexa/ssr',
            'require' => ['semitexa/core' => '*'],
            'extra' => ['semitexa' => ['floors' => ['semitexa/core' => 'next']]],
        ];
    }

    /**
     * THE POINT OF THE WHOLE THING: the version is written once, by the release,
     * and the declaration records what it resolved to.
     */
    #[Test]
    public function the_release_dates_a_declared_floor(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--confirm', '2026.09.18.1500');

        self::assertSame(0, $result['exit'], $result['output']);

        $composer = $this->readPackage();
        self::assertSame('>=2026.09.18.1500 || dev-master', $composer['require']['semitexa/core']);
        self::assertSame('2026.09.18.1500', $composer['extra']['semitexa']['floors']['semitexa/core']);
    }

    /**
     * `|| dev-master` is not decoration. Packages are developed as path
     * repositories, where composer takes the version from the branch, and a
     * branch version satisfies no date floor — so a floor without the escape
     * makes the workspace uninstallable while protecting nobody.
     */
    #[Test]
    public function the_written_floor_admits_the_local_checkout(): void
    {
        $this->writePackage($this->declaringPackage());

        $this->resolve('--confirm', '2026.09.18.1500');

        self::assertStringContainsString('|| dev-master', $this->readPackage()['require']['semitexa/core']);
    }

    /** Resolving twice is a no-op: `next` means the cut it was resolved in, not the newest one. */
    #[Test]
    public function a_dated_floor_is_not_moved_by_the_next_release(): void
    {
        $this->writePackage($this->declaringPackage());
        $this->resolve('--confirm', '2026.09.18.1500');

        $result = $this->resolve('--check', '2026.09.19.0900');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('>=2026.09.18.1500 || dev-master', $this->readPackage()['require']['semitexa/core']);
    }

    /**
     * A dependency that is not being tagged cannot have grown the API the floor
     * is for, so the declaration is an authoring mistake. Resolving it anyway
     * would write a floor at a version that says nothing about the class or
     * method it was meant to guard.
     */
    #[Test]
    public function a_floor_on_a_package_outside_the_release_set_is_refused(): void
    {
        $this->writePackage($this->declaringPackage(), providerIsBeingTagged: false);

        $result = $this->resolve('--confirm', '2026.09.18.1500');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('is not being tagged', $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core'], 'a refused resolution writes nothing');
    }

    /**
     * Preflight's job: a cut may not proceed while a floor still says "next",
     * because the package would ship a constraint that guards nothing.
     */
    #[Test]
    public function check_fails_while_a_declaration_is_undated(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--check', '2026.09.18.1500');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('still name the release', $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core'], 'check changes nothing');
    }

    /**
     * And on develop, where there is no version yet, the same state is reported
     * as what it is rather than resolved to something invented.
     */
    #[Test]
    public function without_a_release_version_it_says_so_and_writes_nothing(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--check');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('RELEASE_VERSION is not set', $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core']);
    }

    /** A tree with nothing declared is silent and successful. */
    #[Test]
    public function a_package_that_declares_no_floor_is_left_alone(): void
    {
        $this->writePackage(['name' => 'semitexa/ssr', 'require' => ['semitexa/core' => '*']]);

        $result = $this->resolve('--check', '2026.09.18.1500');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('*', $this->readPackage()['require']['semitexa/core']);
    }

    /**
     * WRITING THE FLOOR IS NOT LANDING IT. bump-packages.php tags each package
     * after `git reset --hard origin/master`, so an edit left in the working
     * tree is discarded before the tag: the release ships the old constraint
     * while the operator watched the new one being written.
     */
    #[Test]
    public function writing_without_committing_says_the_edit_will_not_survive(): void
    {
        $this->writePackage($this->declaringPackage());

        $result = $this->resolve('--confirm', '2026.09.18.1500');

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('NOT COMMITTED', $result['output']);
        self::assertStringContainsString('reset --hard origin/master', $result['output']);
    }

    /** With --commit the floor reaches origin/master, which is where the tag is cut from. */
    #[Test]
    public function commit_puts_the_resolved_floor_on_origin_master(): void
    {
        $this->writePackage($this->declaringPackage());

        $remote = $this->root . '/origin-ssr.git';
        exec(sprintf('git init -q --bare %s', escapeshellarg($remote)));
        $this->git($this->root . '/packages/semitexa-ssr', 'remote add origin ' . escapeshellarg($remote));
        $this->git($this->root . '/packages/semitexa-ssr', 'push -q origin HEAD:master');

        $result = $this->resolve('--confirm --commit', '2026.09.18.1500');

        self::assertSame(0, $result['exit'], $result['output']);

        $onRemote = shell_exec(sprintf(
            'git -C %s show master:composer.json 2>/dev/null',
            escapeshellarg($remote),
        ));
        $composer = json_decode((string) $onRemote, true);

        self::assertIsArray($composer);
        self::assertSame(
            '>=2026.09.18.1500 || dev-master',
            $composer['require']['semitexa/core'],
            'the tagger resets to origin/master, so this is the only copy that counts',
        );
    }

    /**
     * And it refuses to commit anywhere but master: the tag is cut from master,
     * so a commit on another branch is not in the release however green it looks.
     */
    #[Test]
    public function commit_refuses_a_checkout_that_is_not_on_master(): void
    {
        $this->writePackage($this->declaringPackage());
        $this->git($this->root . '/packages/semitexa-ssr', 'checkout -q -b develop');

        $result = $this->resolve('--confirm --commit', '2026.09.18.1500');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('not master', $result['output']);
    }

    /**
     * The set of packages being tagged is DERIVED — "master HEAD carries no
     * release tag", the same rule the tagger uses. It was an environment
     * variable nothing set, so the empty value meant "assume everything is being
     * released" and the refusal above could never fire.
     */
    #[Test]
    public function the_release_set_is_derived_rather_than_assumed(): void
    {
        $this->writePackage($this->declaringPackage(), providerIsBeingTagged: false);

        $result = $this->resolve('--confirm', '2026.09.18.1500');

        self::assertSame(1, $result['exit'], 'core carries a tag on HEAD, so it is not being released');
        self::assertStringContainsString('semitexa/core is not being tagged', $result['output']);
    }

    /**
     * A commit that carries somebody else's work to master.
     *
     * `git add composer.json` would stage an unrelated edit to the same file
     * along with the floor, and an unrestricted `git commit` would sweep in
     * anything already in the index. Both reach master, where the tag is cut.
     */
    #[Test]
    public function a_dirty_repository_is_refused_before_anything_is_written(): void
    {
        $this->writePackage($this->declaringPackage());
        $this->withRemote();

        file_put_contents($this->root . '/packages/semitexa-ssr/UNRELATED.md', "someone else's work\n");
        $this->git($this->root . '/packages/semitexa-ssr', 'add -A');

        $result = $this->resolve('--confirm --commit', '2026.09.18.1500');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('uncommitted changes', $result['output']);
        self::assertSame(
            'next',
            $this->readPackage()['extra']['semitexa']['floors']['semitexa/core'],
            'refusing before the first write is what leaves nothing behind',
        );
    }

    /**
     * A push that fails must leave the declaration PENDING. Dated-but-unpushed
     * is the worst state available: a retry finds no `next`, reports success,
     * and finalize then resets the file away — so the release ships the old
     * constraint with nothing left to show why.
     */
    #[Test]
    public function a_failed_push_restores_the_declaration_for_a_retry(): void
    {
        $this->writePackage($this->declaringPackage());

        // A remote that cannot be pushed to.
        $this->git($this->root . '/packages/semitexa-ssr', 'remote add origin ' . escapeshellarg($this->root . '/nowhere.git'));

        $result = $this->resolve('--confirm --commit', '2026.09.18.1500');

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('git push failed', $result['output']);

        $composer = $this->readPackage();
        self::assertSame('next', $composer['extra']['semitexa']['floors']['semitexa/core'], 'still pending');
        self::assertSame('*', $composer['require']['semitexa/core'], 'and the constraint is back');

        $log = shell_exec(sprintf(
            'git -C %s log --oneline -1 2>/dev/null',
            escapeshellarg($this->root . '/packages/semitexa-ssr'),
        ));
        self::assertStringNotContainsString('Date the declared internal floors', (string) $log, 'the local commit is undone too');
    }

    /**
     * Membership comes from MASTER, not the checked-out HEAD. A provider sitting
     * on an untagged develop while origin/master is already released would
     * otherwise join the release set, and a consumer floor would be dated
     * against a provider this cut never tags.
     */
    #[Test]
    public function a_provider_checked_out_on_another_branch_is_judged_by_its_master(): void
    {
        $this->writePackage($this->declaringPackage(), providerIsBeingTagged: false);
        $this->git($this->root . '/packages/semitexa-core', 'checkout -q -b develop');
        $this->git($this->root . '/packages/semitexa-core', 'commit -q --allow-empty -m "work in progress"');

        $result = $this->resolve('--confirm', '2026.09.18.1500');

        self::assertSame(1, $result['exit'], 'master carries the tag, so core is not being released');
        self::assertStringContainsString('semitexa/core is not being tagged', $result['output']);
    }

    private function withRemote(): void
    {
        $remote = $this->root . '/origin-ssr.git';
        exec(sprintf('git init -q --bare %s', escapeshellarg($remote)));
        $this->git($this->root . '/packages/semitexa-ssr', 'remote add origin ' . escapeshellarg($remote));
        $this->git($this->root . '/packages/semitexa-ssr', 'push -q origin HEAD:master');
    }
}