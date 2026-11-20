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
 * tag (Fable's review of the step 2 plan, D2), and derive-manifest.php --check
 * only while update still ships the runtime entries. Both run on the host:
 * review on dev#283 found the stage exec'ing into an app container that is not
 * up before preflight starts it, and reporting the failed exec as a stale
 * manifest. Only a tool's own "stale" answer (exit 1) is called stale.
 *
 * The tools here are stand-ins that answer as told and print the arguments
 * they got, so each case is about the stage, not about the tools.
 */
final class ReleaseInstallerManifestCheckTest extends TestCase
{
    private const STALE = 'does not hold';
    private const BROKEN = 'Could not check';
    private const INSTALLER = '/packages/semitexa-installer';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-manifest-check-' . bin2hex(random_bytes(4));
        mkdir($this->root . self::INSTALLER . '/tools', 0777, true);
        mkdir($this->root . self::INSTALLER . '/resources', 0777, true);
        mkdir($this->root . '/packages/semitexa-update/resources', 0777, true);
        $this->updateShipsTheRuntimeEntries(true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_manifest_both_tools_accept_passes_with_no_container_to_exec_into(): void
    {
        $this->tool('check-manifest.php', 0, 'scaffold-manifest.json describes scaffold/ (19 files).');
        $this->tool('derive-manifest.php', 0, 'scaffold-manifest.json is current (19 files).');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('ran check-manifest.php', $output);
        self::assertStringContainsString('ran derive-manifest.php --check', $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function a_manifest_the_check_refuses_fails_and_says_how_to_rebuild_it(): void
    {
        $this->tool('check-manifest.php', 1, 'bin/semitexa: the file hashes to 1111, the manifest says 2222.');
        $this->tool('derive-manifest.php', 0, 'is current');

        [$status, $output] = $this->check();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString('bin/semitexa: the file hashes to 1111', $output);
        self::assertStringContainsString(self::STALE, $output);
        self::assertStringContainsString('bin/semitexa scaffold:sync', $output);
        self::assertStringNotContainsString('ran derive-manifest.php', $output, 'the stage went on after a refusal');
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
    public function a_tool_that_crashes_is_an_error_not_a_stale_manifest(): void
    {
        file_put_contents($this->root . self::INSTALLER . '/tools/check-manifest.php', "<?php\nthrow new \\RuntimeException('broken tool');\n");

        [$status, $output] = $this->check();

        self::assertSame(255, $status, $output);
        self::assertStringContainsString(self::BROKEN, $output);
        self::assertStringNotContainsString(self::STALE, $output);
    }

    #[Test]
    public function while_update_ships_the_runtime_entries_a_stale_derived_manifest_fails(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        $this->tool('derive-manifest.php', 1, 'scaffold-manifest.json is stale');

        [$status, $output] = $this->check();

        self::assertSame(1, $status, $output);
        self::assertStringContainsString(self::STALE, $output);
        self::assertStringContainsString('php packages/semitexa-installer/tools/derive-manifest.php', $output);
    }

    #[Test]
    public function once_update_lists_none_of_the_installers_files_the_comparison_is_not_run(): void
    {
        // After step 2b update's own manifest holds only the agent docs; the
        // derive tool would refuse for want of entries, which is not a defect.
        $this->updateShipsTheRuntimeEntries(false);
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        $this->tool('derive-manifest.php', 1, "No entry in update's manifest for: bin/semitexa");

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('ran check-manifest.php', $output);
        self::assertStringNotContainsString('ran derive-manifest.php', $output);
    }

    #[Test]
    public function the_history_is_checked_against_the_manifest_at_the_newest_release_tag(): void
    {
        $this->tool('check-manifest.php', 0, 'describes scaffold/');
        $this->git('init -q -b develop');
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
        $this->git('init -q -b develop');

        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('has no release tag', $output);
        self::assertStringContainsString('ran check-manifest.php' . "\n", $output . "\n");
        self::assertStringNotContainsString('--previous', $output);
    }

    #[Test]
    public function a_release_whose_installer_has_no_manifest_tools_passes(): void
    {
        [$status, $output] = $this->check();

        self::assertSame(0, $status, $output);
        self::assertStringContainsString('nothing to check', $output);
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

    private function updateShipsTheRuntimeEntries(bool $ships): void
    {
        $files = [['path' => 'AGENTS.md']];
        if ($ships) {
            $files[] = ['path' => 'bin/semitexa'];
        }
        file_put_contents(
            $this->root . '/packages/semitexa-update/resources/scaffold-manifest.json',
            json_encode(['files' => $files], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
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
