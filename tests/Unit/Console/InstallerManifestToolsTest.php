<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Console\InstallerManifestTools;

/**
 * What scaffold:sync runs for semitexa/installer's own manifest, which update
 * reads from vendor/ from step 2 of one source for bin/semitexa: the installer's
 * check at verify time, with the history against its last release tag.
 */
final class InstallerManifestToolsTest extends TestCase
{
    // verify:accept-test-change the derive tool and update's runtime entries are gone (installer bbf0566, step 2d of tk-one-source-for-bin-semitexa); the tests of that branch go with it

    private const INSTALLER = '/packages/semitexa-installer';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-manifest-tools-' . bin2hex(random_bytes(4));
        mkdir($this->root . self::INSTALLER . '/tools', 0777, true);
        mkdir($this->root . self::INSTALLER . '/scaffold/bin', 0777, true);
        mkdir($this->root . self::INSTALLER . '/resources', 0777, true);
        file_put_contents($this->root . self::INSTALLER . '/scaffold/bin/semitexa', "#!/usr/bin/env bash\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function the_check_runs_the_installers_own_tool(): void
    {
        $this->tools('check-manifest.php');
        $command = (new InstallerManifestTools($this->root))->checkCommand();

        self::assertSame('check-manifest.php', $command['label']);
        self::assertSame([$this->root . self::INSTALLER . '/tools/check-manifest.php'], $command['argv']);
    }

    #[Test]
    public function a_check_without_the_check_tool_cannot_run(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('check-manifest.php is missing');
        (new InstallerManifestTools($this->root))->checkCommand();
    }

    #[Test]
    public function the_history_check_is_given_the_earlier_manifest(): void
    {
        $this->tools('check-manifest.php');
        $command = (new InstallerManifestTools($this->root))->checkCommand('/tmp/earlier.json');

        self::assertSame([$this->root . self::INSTALLER . '/tools/check-manifest.php', '--previous=/tmp/earlier.json'], $command['argv']);
    }

    #[Test]
    public function the_manifest_at_the_newest_release_tag_is_written_out(): void
    {
        $this->git('init -q -b develop');
        // The newest tagged first: by version, not by when a tag was made.
        $this->tagManifest('2026.02.01.0000', '{"files": "newest"}');
        $this->tagManifest('2026.01.31.2359', '{"files": "older"}');
        $this->tagManifest('not-a-release', '{"files": "not a release"}');
        $file = $this->root . '/earlier.json';

        $tag = (new InstallerManifestTools($this->root))->writeManifestAtLastTag($file);

        self::assertSame('2026.02.01.0000', $tag);
        self::assertSame('{"files": "newest"}' . "\n", file_get_contents($file));
    }

    #[Test]
    public function an_installer_with_no_release_tag_gives_no_earlier_manifest(): void
    {
        $this->git('init -q -b develop');
        $file = $this->root . '/earlier.json';

        self::assertNull((new InstallerManifestTools($this->root))->writeManifestAtLastTag($file));
        self::assertFileDoesNotExist($file, 'nothing was written');
    }

    #[Test]
    public function a_tag_from_before_the_installer_had_a_manifest_gives_no_earlier_manifest(): void
    {
        $this->git('init -q -b develop');
        file_put_contents($this->root . self::INSTALLER . '/README.md', "# installer\n");
        $this->git('add README.md');
        $this->git('-c user.name=test -c user.email=test@example.invalid commit -q -m before');
        $this->git('tag 2026.01.01.0000');
        $file = $this->root . '/earlier.json';

        self::assertNull((new InstallerManifestTools($this->root))->writeManifestAtLastTag($file));
        self::assertFileDoesNotExist($file);
    }

    /**
     * Review on dev#291: git failing (here: no repository) read as an installer
     * without releases, and the history check ran without --previous.
     */
    #[Test]
    public function tags_git_cannot_list_are_an_error_not_an_installer_without_releases(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('git could not list the release tags');
        (new InstallerManifestTools($this->root))->writeManifestAtLastTag($this->root . '/earlier.json');
    }

    #[Test]
    public function a_tool_runs_with_this_php_and_answers_with_its_exit_code_and_both_streams(): void
    {
        $tool = $this->root . self::INSTALLER . '/tools/check-manifest.php';
        file_put_contents($tool, "<?php\necho 'out ' . (\$argv[1] ?? '') . \"\\n\";\nfwrite(STDERR, \"err\\n\");\nexit(1);\n");

        [$exit, $output] = (new InstallerManifestTools($this->root))->run([$tool, '--previous=x']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('out --previous=x', $output);
        self::assertStringContainsString('err', $output);
    }

    private function tools(string ...$names): void
    {
        foreach ($names as $name) {
            file_put_contents($this->root . self::INSTALLER . '/tools/' . $name, "<?php\nexit(0);\n");
        }
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
}
