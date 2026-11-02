<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\ModulePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;

class ModulePlanBuilderTest extends TestCase
{
    /**
     * The module's roots only (bench-make-module-empty-dirs): ten empty
     * folders were friction, and each generator creates its folder with the
     * first class in it (the canonical Console/Command path is pinned where
     * commands are generated, CommandPlanBuilderTest).
     */
    public function test_builds_custom_module_under_src_modules_by_default(): void
    {
        // verify:accept-test-change make:module writes only the module roots now (bench-make-module-empty-dirs): the exact file list is pinned instead, and the Console/Command path is pinned where commands are generated (CommandPlanBuilderTest).
        $plan = (new ModulePlanBuilder(new NameInflector()))->build(['name' => 'Catalog', 'dryRun' => true]);

        $this->assertSame(
            ['src/modules/Catalog/src/.gitkeep', 'src/modules/Catalog/tests/.gitkeep'],
            array_map(static fn ($file) => $file->path, $plan->files),
        );
    }

    public function test_builds_package_module_with_composer_manifest(): void
    {
        $plan = (new ModulePlanBuilder(new NameInflector()))->build([
            'name' => 'Catalog',
            'target' => ModulePlanBuilder::TARGET_PACKAGE,
            'dryRun' => true,
        ]);

        $this->assertSame(
            ['packages/semitexa-catalog/composer.json', 'packages/semitexa-catalog/src/.gitkeep'],
            array_map(static fn ($file) => $file->path, $plan->files),
        );
        $this->assertStringContainsString('"type": "semitexa-module"', $plan->files[0]->content);
        $this->assertStringContainsString('"name": "semitexa/catalog"', $plan->files[0]->content);
    }
}
