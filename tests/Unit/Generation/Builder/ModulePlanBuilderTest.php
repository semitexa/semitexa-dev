<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\ModulePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;

class ModulePlanBuilderTest extends TestCase
{
    public function test_builds_custom_module_under_src_modules_by_default(): void
    {
        $builder = new ModulePlanBuilder(new NameInflector());

        $plan = $builder->build([
            'name' => 'Catalog',
            'dryRun' => true,
        ]);

        $this->assertCount(11, $plan->files);
        $this->assertSame('src/modules/Catalog/src/Application/Payload/Request/.gitkeep', $plan->files[0]->path);
        $this->assertSame('src/modules/Catalog/src/Domain/Model/.gitkeep', $plan->files[9]->path);
        $this->assertSame('src/modules/Catalog/tests/.gitkeep', $plan->files[10]->path);
    }

    public function test_builds_package_module_with_composer_manifest(): void
    {
        $builder = new ModulePlanBuilder(new NameInflector());

        $plan = $builder->build([
            'name' => 'Catalog',
            'target' => ModulePlanBuilder::TARGET_PACKAGE,
            'dryRun' => true,
        ]);

        $this->assertCount(11, $plan->files);
        $this->assertSame('packages/semitexa-catalog/composer.json', $plan->files[0]->path);
        $this->assertStringContainsString('"type": "semitexa-module"', $plan->files[0]->content);
        $this->assertStringContainsString('"name": "semitexa/catalog"', $plan->files[0]->content);
        $this->assertSame('packages/semitexa-catalog/src/Application/Payload/Request/.gitkeep', $plan->files[1]->path);
        $this->assertSame('packages/semitexa-catalog/src/Domain/Model/.gitkeep', $plan->files[10]->path);
    }

    public function test_skeleton_uses_canonical_console_command_path_for_custom_module(): void
    {
        // Regression pin: the generator must emit Application/Console/Command/,
        // never the deprecated Application/Command/ that the module_structure
        // validator rejects.
        $builder = new ModulePlanBuilder(new NameInflector());
        $plan = $builder->build(['name' => 'Catalog']);

        $paths = array_map(static fn ($file) => $file->path, $plan->files);
        $this->assertContains('src/modules/Catalog/src/Application/Console/Command/.gitkeep', $paths);
        $this->assertNotContains('src/modules/Catalog/src/Application/Command/.gitkeep', $paths);
    }

    public function test_skeleton_uses_canonical_console_command_path_for_package(): void
    {
        $builder = new ModulePlanBuilder(new NameInflector());
        $plan = $builder->build([
            'name' => 'Catalog',
            'target' => ModulePlanBuilder::TARGET_PACKAGE,
        ]);

        $paths = array_map(static fn ($file) => $file->path, $plan->files);
        $this->assertContains('packages/semitexa-catalog/src/Application/Console/Command/.gitkeep', $paths);
        $this->assertNotContains('packages/semitexa-catalog/src/Application/Command/.gitkeep', $paths);
    }
}
