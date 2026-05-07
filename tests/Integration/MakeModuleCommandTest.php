<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Console\Command\MakeModuleCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
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
        $this->assertContains('src/modules/Catalog/src/Application/Payload/Request/.gitkeep', $decoded['result']['created']);
        $this->assertContains('--target=custom', $decoded['result']['replay_args']);
    }

    public function test_custom_target_emits_canonical_console_command_path(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $tester->execute([
            '--name' => 'Catalog',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $created = json_decode(trim($tester->getDisplay()), true)['result']['created'];

        // Canonical: Application/Console/Command/ — required by the
        // module_structure validator. This pins the regression so the
        // generator can never silently regress to Application/Command/.
        $this->assertContains(
            'src/modules/Catalog/src/Application/Console/Command/.gitkeep',
            $created,
        );
        $this->assertNotContains(
            'src/modules/Catalog/src/Application/Command/.gitkeep',
            $created,
            'make:module must not emit the deprecated Application/Command/ path',
        );
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

    public function test_package_target_also_emits_canonical_console_command_path(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $tester->execute([
            '--name' => 'Catalog',
            '--target' => 'package',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $created = json_decode(trim($tester->getDisplay()), true)['result']['created'];

        $this->assertContains(
            'packages/semitexa-catalog/src/Application/Console/Command/.gitkeep',
            $created,
        );
        $this->assertNotContains(
            'packages/semitexa-catalog/src/Application/Command/.gitkeep',
            $created,
        );
    }

    public function test_written_module_passes_module_structure_validation(): void
    {
        // True end-to-end: --write the module to the temp project root,
        // then run the real ModuleStructureValidator (loaded from the
        // executable spec) over the result. Zero violations is the bar.
        $tester = new CommandTester(new MakeModuleCommand());
        $exit = $tester->execute([
            '--name' => 'Catalog',
            '--write' => true,
        ]);
        $this->assertSame(0, $exit, 'make:module --write must succeed');

        $this->assertDirectoryExists($this->tmpRoot . '/src/modules/Catalog/src/Application/Console/Command');
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/src/modules/Catalog/src/Application/Command');

        // Real project root holds the executable spec; the validator runs
        // against the temp $tmpRoot which holds the freshly generated module.
        $projectRoot = dirname(__DIR__, 4);
        $spec = (new ModuleStructureSpecLoader($projectRoot))->load();
        $validator = new ModuleStructureValidator($this->tmpRoot, $spec);

        $module = new DetectedModule(
            name: 'Catalog',
            relativePath: 'src/modules/Catalog',
            kind: DetectedModule::KIND_APPLICATION,
        );

        $violations = $validator->validate($module);

        $this->assertSame(
            [],
            array_map(static fn ($v) => $v->code . ': ' . $v->path, $violations),
            'a freshly scaffolded module must have zero structure violations',
        );
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
        $this->assertStringContainsString('project-specific module in `src/modules/{Module}/`', $display);
        $this->assertStringContainsString('reusable module package in `packages/semitexa-{module}`', $display);
        $this->assertStringContainsString('Package mode currently scaffolds the package shell only.', $display);
        $this->assertStringContainsString('packages/semitexa-catalog/composer.json', $display);
    }

    public function test_package_target_does_not_suggest_custom_module_generators(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $exit = $tester->execute([
            '--name' => 'Catalog',
            '--target' => 'package',
            '--write' => true,
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('package mode currently scaffolds the shell only', strtolower($display));
        $this->assertStringNotContainsString('Next: use make:page, make:service, or make:contract to add code.', $display);
    }

    public function test_rejects_empty_target_value(): void
    {
        $tester = new CommandTester(new MakeModuleCommand());
        $exit = $tester->execute([
            '--name' => 'Catalog',
            '--target' => '',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Invalid --target. Allowed values: custom, package.', $tester->getDisplay());
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
