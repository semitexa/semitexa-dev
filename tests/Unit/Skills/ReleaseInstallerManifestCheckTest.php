<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The preflight stage installer-manifest-fresh: semitexa/installer's scaffold
 * manifest, which semitexa/update reads from vendor/ from step 2 of one source
 * for bin/semitexa, holds before a release.
 *
 * It runs tools/check-manifest.php against the manifest at the installer's last
 * tag (Fable's review of the step 2 plan, D2), on the host:
 * review on dev#283 found the stage exec'ing into an app container that is not
 * up before preflight starts it, and reporting the failed exec as a stale
 * manifest. Only a tool's own "stale" answer (exit 1) is called stale.
 *
 * The tools here are stand-ins that answer as told and print the arguments
 * they got, so each case is about the stage, not about the tools.
 */
final class ReleaseInstallerManifestCheckTest extends TestCase
{
    // verify:accept-test-change the derive tool and update's runtime entries are gone (installer bbf0566, step 2d of tk-one-source-for-bin-semitexa); the tests of that branch go with it

    private const STALE = 'does not hold';
    private const BROKEN = 'Could not check';
    private const INSTALLER = '/packages/semitexa-installer';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-manifest-check-' . bin2hex(random_bytes(4));
        mkdir($this->root . self::INSTALLER . '/tools', 0777, true);
        mkdir($this->root . self::INSTALLER . '/resources', 0777, true);
        mkdir($this->root . self::INSTALLER . '/scaffold/bin', 0777, true);
        file_put_contents($this->root . self::INSTALLER . '/scaffold/bin/semitexa', "#!/usr/bin/env bash\n");
        // A repository with no release tag yet: an answer, unlike git failing.
        $this->git('init -q -b develop');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_manifest_the_check_accepts_passes_with_no_container_to_exec_into(): void
    {
        $this->tool('check-manifest.php', 0, 'scaffold-manifest.json describes scaffold/ (19 files).');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('ran check-manifest.php', $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function a_manifest_the_check_refuses_fails_and_says_how_to_rebuild_it(): void
    {
        $this->tool('check-manifest.php', 1, 'bin/semitexa: the file hashes to 1111, the manifest says 2222.');

        [$status, $output] = $this->check();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('bin/semitexa: the file hashes to 1111', $output);
        self::assertStringContainsString(self::STALE, $output);
        self::assertStringContainsString('bin/semitexa scaffold:sync', $output);
    }

    #[Test]
    public function a_check_that_cannot_read_is_an_error_not_a_stale_manifest(): void
    {
        $this->tool('check-manifest.php', 2, 'Cannot read packages/semitexa-installer/resources/scaffold-manifest.json');

        [$status, $output] = $this->check();

        self::assertSame(2, $status, $output);
        self::assertStringContainsString(self::BROKEN, $output);
        self::assertStringContainsString('check-manifest.php exited 2', $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function an_installer_without_the_check_tool_cannot_pass(): void
    {
        [$status, $output] = $this->check();

        self::assertSame(2, $status, $output);
        self::assertStringContainsString(self::BROKEN, $output);
        self::assertStringContainsString('check-manifest.php is missing', $output);
    }

    /**
     * Review on dev#291: `git tag … || true` turned a repository git could not
     * read into "no release tag", and the history check silently ran without
     * --previous.
     */
    #[Test]
    public function tags_git_cannot_list_are_an_error_not_an_installer_without_releases(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        exec('rm -rf ' . escapeshellarg($this->root . self::INSTALLER . '/.git'));

        [$status, $output] = $this->check();

        self::assertSame(2, $status, $output);
        self::assertStringContainsString('git could not list the release tags', $output);
        self::assertStringNotContainsString('ran check-manifest.php', $output);
    }

    #[Test]
    public function a_tag_from_before_the_installer_had_a_manifest_is_checked_without_a_history(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        file_put_contents($this->root . self::INSTALLER . '/README.md', "# installer\n");
        $this->git('add README.md');
        $this->git('-c user.name=test -c user.email=test@example.invalid commit -q -m before');
        $this->git('tag 2026.01.01.0000');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('last tag 2026.01.01.0000 has no manifest', $output);
        self::assertStringNotContainsString('--previous', $output);
    }

    #[Test]
    public function a_tool_that_crashes_is_an_error_not_a_stale_manifest(): void
    {
        file_put_contents($this->root . self::INSTALLER . '/tools/check-manifest.php', "<?php\nthrow new \\RuntimeException('broken tool');\n");

        [$status, $output] = $this->check();

        self::assertSame(255, $status, $output);
        self::assertStringContainsString(self::BROKEN, $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function the_history_is_checked_against_the_manifest_at_the_newest_release_tag(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        // The newest release tagged first: the stage goes by version, not by
        // when a tag was made, and not by `git describe` (tags sit on master's
        // merge commits, which develop does not contain).
        $this->tagManifest('2026.02.01.0000', '{"files": "newest"}');
        $this->tagManifest('2026.01.31.2359', '{"files": "older"}');
        $this->tagManifest('not-a-release', '{"files": "not a release"}');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('History checked against 2026.02.01.0000.', $output);
        self::assertStringContainsString('previous held {"files": "newest"}', $output);
    }

    #[Test]
    public function an_installer_with_no_release_tag_is_checked_without_a_history(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('has no release tag', $output);
        self::assertStringContainsString('ran check-manifest.php' . "\n", $output . "\n");
        self::assertStringNotContainsString('--previous', $output);
    }

    /** A stand-in tool: says what it ran with, then answers $message with $exit. */
    private function tool(string $name, int $exit, string $message): void
    {
        $stream = $exit === 0 ? 'STDOUT' : 'STDERR';
        $code = <<<'PHP'
            <?php
            $arguments = array_slice($argv, 1);
            echo 'ran ' . trim(basename(__FILE__) . ' ' . implode(' ', array_map(static fn (string $a): string => str_starts_with($a, '--previous=') ? '--previous' : $a, $arguments))) . "\n";
            foreach ($arguments as $argument) {
                if (str_starts_with($argument, '--previous=')) {
                    echo 'previous held ' . trim((string) file_get_contents(substr($argument, strlen('--previous=')))) . "\n";
                }
            }
            PHP;
        $code .= sprintf("\nfwrite(%s, %s . \"\\n\");\nexit(%d);\n", $stream, var_export($message, true), $exit);
        file_put_contents($this->root . self::INSTALLER . '/tools/' . $name, $code);
    }

    private function tagManifest(string $tag, string $manifest): void
    {
        file_put_contents($this->root . self::INSTALLER . '/resources/scaffold-manifest.json', $manifest . "\n");
        $this->git('add resources/scaffold-manifest.json');
        $this->git('-c user.name=test -c user.email=test@example.invalid commit -q -m ' . escapeshellarg($tag));
        $this->git('tag ' . escapeshellarg($tag));
    }

    private function git(string $arguments): void
    {
        exec(sprintf('git -C %s %s 2>&1', escapeshellarg($this->root . self::INSTALLER), $arguments), $output, $status);
        self::assertSame(0, $status, "git {$arguments}: " . implode("\n", $output));
    }

    /**
     * Runs the stage with a PATH that holds php and git and no docker, as on a
     * host where the dev stack is down.
     *
     * @return array{int, string}
     */
    private function check(): array
    {
        $script = dirname(__DIR__, 3) . '/resources/skills/release-readiness/scripts/release-installer-manifest-check.sh';
        $php = dirname(PHP_BINARY);
        exec(sprintf(
            'env -i PATH=%s HOME=%s DEV_ROOT=%s bash %s 2>&1',
            escapeshellarg($php . ':/usr/bin:/bin'),
            escapeshellarg($this->root),
            escapeshellarg($this->root),
            escapeshellarg($script),
        ), $out, $status);

        return [$status, implode("\n", $out)];
    }
}
