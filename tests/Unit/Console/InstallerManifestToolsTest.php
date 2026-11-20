<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Console\InstallerManifestTools;

/**
 * What scaffold:sync runs for semitexa/installer's own manifest, which update
 * reads from vendor/ from step 2 of one source for bin/semitexa: both checks at
 * verify time, the history against the installer's last release tag, and the
 * derive tool only while update still ships the runtime entries itself.
 */
final class InstallerManifestToolsTest extends TestCase
{
    private const INSTALLER = '/packages/semitexa-installer';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/installer-manifest-tools-' . bin2hex(random_bytes(4));
        mkdir($this->root . self::INSTALLER . '/tools', 0777, true);
        mkdir($this->root . self::INSTALLER . '/scaffold/bin', 0777, true);
        mkdir($this->root . self::INSTALLER . '/resources', 0777, true);
        mkdir($this->root . '/packages/semitexa-update/resources', 0777, true);
        file_put_contents($this->root . self::INSTALLER . '/scaffold/bin/semitexa', "#!/usr/bin/env bash\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function while_update_ships_the_runtime_entries_both_checks_run(): void
    {
        $this->tools('check-manifest.php', 'derive-manifest.php');
        $this->updateManifest(['AGENTS.md', 'bin/semitexa']);
        $tools = new InstallerManifestTools($this->root);

        self::assertTrue($tools->updateShipsInstallerFiles());
        self::assertSame(['derive-manifest.php --check', 'check-manifest.php'], array_column($tools->commands(true), 'label'));
        self::assertSame(['derive-manifest.php'], array_column($tools->commands(false), 'label'), 'a write keeps the installer manifest in step with the update rebuild before it');
    }

    #[Test]
    public function once_update_lists_none_of_the_installers_files_nothing_is_derived(): void
    {
        $this->tools('check-manifest.php', 'derive-manifest.php');
        // After step 2b: update's own manifest holds only the agent docs, and a
        // path the installer does not ship is not one of its files.
        $this->updateManifest(['AGENTS.md', 'CLAUDE.md', 'docker-compose.legacy.yml']);
        $tools = new InstallerManifestTools($this->root);

        self::assertFalse($tools->updateShipsInstallerFiles());
        self::assertSame(['check-manifest.php'], array_column($tools->commands(true), 'label'));
        self::assertSame([], $tools->commands(false));
    }

    #[Test]
    public function an_unreadable_update_manifest_ships_nothing(): void
    {
        $this->tools('derive-manifest.php');
        file_put_contents($this->root . '/packages/semitexa-update/resources/scaffold-manifest.json', '{"files": [');

        self::assertFalse((new InstallerManifestTools($this->root))->updateShipsInstallerFiles());
    }

    #[Test]
    public function an_installer_without_the_tools_has_nothing_to_run(): void
    {
        $this->updateManifest(['bin/semitexa']);

        self::assertSame([], (new InstallerManifestTools($this->root))->commands(true));
    }

    #[Test]
    public function the_history_check_is_given_the_earlier_manifest(): void
    {
        $this->tools('check-manifest.php');
        $commands = (new InstallerManifestTools($this->root))->commands(true, '/tmp/earlier.json');

        self::assertSame([$this->root . self::INSTALLER . '/tools/check-manifest.php', '--previous=/tmp/earlier.json'], $commands[0]['argv']);
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

    /** @param list<string> $paths */
    private function updateManifest(array $paths): void
    {
        file_put_contents(
            $this->root . '/packages/semitexa-update/resources/scaffold-manifest.json',
            json_encode(['files' => array_map(static fn (string $path): array => ['path' => $path], $paths)], JSON_THROW_ON_ERROR),
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
}
