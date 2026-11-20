<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ActionlintTool;
use Semitexa\Dev\Tests\Unit\Ai\Verify\Fixture\FakeDownloadRunner;

/** What the fetch refuses: every case here ends with no tool and nothing installed. */
final class ActionlintToolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-actionlint-tool-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    #[Test]
    public function the_pins_are_the_two_linux_archives_of_the_release(): void
    {
        self::assertSame('1.7.7', ActionlintTool::VERSION);
        self::assertSame('var/tools/actionlint/1.7.7', ActionlintTool::INSTALL_DIR);
        self::assertSame(
            [
                'amd64' => '023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757',
                'arm64' => '401942f9c24ed71e4fe71b76c7d638f66d8633575c4016efd2977ce7c28317d0',
            ],
            ActionlintTool::SHA256,
        );
        self::assertSame('actionlint_1.7.7_linux_arm64.tar.gz', ActionlintTool::assetName('arm64'));
    }

    #[Test]
    public function a_download_whose_hash_is_not_the_pinned_one_is_never_unpacked(): void
    {
        $runner = new FakeDownloadRunner('not the release archive');

        $got = (new ActionlintTool($this->root, $runner, machine: 'x86_64', osFamily: 'Linux'))->obtain();

        self::assertSame(
            ['unavailable' => 'the downloaded actionlint_1.7.7_linux_amd64.tar.gz has sha256 ' . hash('sha256', 'not the release archive')
                . ', not the pinned 023070a287cd8cccd71515fedc843f1985bf96c436b7effaecce67290e7e0757; it was not unpacked'],
            $got,
        );
        self::assertSame(['curl'], $runner->programs, 'tar never ran');
        self::assertSame('https://github.com/rhysd/actionlint/releases/download/v1.7.7/actionlint_1.7.7_linux_amd64.tar.gz', $runner->url);
        self::assertSame(['.', '..'], scandir($this->root . '/' . ActionlintTool::INSTALL_DIR), 'nothing is left in the tool directory');
    }

    #[Test]
    public function a_download_that_fails_names_the_reason(): void
    {
        $runner = new FakeDownloadRunner(null, 'curl: (6) Could not resolve host: github.com');

        $got = (new ActionlintTool($this->root, $runner, machine: 'aarch64', osFamily: 'Linux'))->obtain();

        self::assertSame(
            ['unavailable' => 'could not download https://github.com/rhysd/actionlint/releases/download/v1.7.7/actionlint_1.7.7_linux_arm64.tar.gz: curl: (6) Could not resolve host: github.com'],
            $got,
        );
    }

    #[Test]
    public function a_machine_with_no_pinned_build_fetches_nothing(): void
    {
        foreach ([['riscv64', 'Linux', 'Linux riscv64'], ['arm64', 'Darwin', 'Darwin arm64']] as [$machine, $os, $named]) {
            $runner = new FakeDownloadRunner('x');

            $got = (new ActionlintTool($this->root, $runner, machine: $machine, osFamily: $os))->obtain();

            self::assertSame(['unavailable' => "no pinned actionlint 1.7.7 build for {$named} (linux amd64 and arm64 only)"], $got);
            self::assertSame([], $runner->programs);
        }
    }

    #[Test]
    public function an_installed_binary_is_used_without_a_fetch(): void
    {
        $installed = $this->root . '/' . ActionlintTool::INSTALL_DIR . '/actionlint';
        mkdir(dirname($installed), 0755, true);
        file_put_contents($installed, "#!/bin/sh\nexit 0\n");
        chmod($installed, 0755);
        $runner = new FakeDownloadRunner('x');

        self::assertSame(['binary' => $installed], (new ActionlintTool($this->root, $runner))->obtain());
        self::assertSame([], $runner->programs);
    }
}
