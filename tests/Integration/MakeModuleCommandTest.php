<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Console\Command\MakeModuleCommand;
use Symfony\Component\Console\Tester\CommandTester;

class MakeModuleCommandTest extends TestCase
{
    private string $tmpRoot;
    private ?string $originalCwd = null;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-make-module-' . uniqid();
        mkdir($this->tmpRoot . '/src/modules', 0755, true);
        mkdir($this->tmpRoot . '/packages', 0755, true);
        file_put_contents($this->tmpRoot . '/composer.json', '{"name":"temp/project"}');

        $this->originalCwd = getcwd() ?: null;
        chdir($this->tmpRoot);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->originalCwd !== null) {
            chdir($this->originalCwd);
        }
        ProjectRoot::reset();
        $this->removeDir($this->tmpRoot);
    }

    public function test_defaults_to_custom_target_in_json_mode(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $exit = $tester->execute([
            '--name' => 'Catalog',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertSame('semitexa-dev.generation-result/v1', $decoded['artifact']);
        $this->assertContains('src/modules/Catalog/Application/Payload/Request/.gitkeep', $decoded['result']['created']);
        $this->assertContains('--target=custom', $decoded['result']['replay_args']);
    }

    public function test_generates_package_layout_when_target_is_package(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $exit = $tester->execute([
            '--name' => 'Catalog',
            '--target' => 'package',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        $this->assertContains('packages/semitexa-catalog/composer.json', $decoded['result']['created']);
        $this->assertContains('packages/semitexa-catalog/src/Application/Payload/Request/.gitkeep', $decoded['result']['created']);
        $this->assertContains('--target=package', $decoded['result']['replay_args']);
    }

    public function test_interactive_mode_explains_difference_before_prompting(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $tester->setInputs(['package']);

        $exit = $tester->execute([
            '--name' => 'Catalog',
            '--dry-run' => true,
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Choose module target', $display);
        $this->assertStringContainsString('project-specific module in `src/modules/{Module}`', $display);
        $this->assertStringContainsString('reusable module package in `packages/semitexa-{module}`', $display);
        $this->assertStringContainsString('packages/semitexa-catalog/composer.json', $display);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
