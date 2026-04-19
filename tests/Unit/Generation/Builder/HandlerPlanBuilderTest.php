<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation\Builder;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Ai\Convention\ConventionStore;
use Semitexa\Dev\Ai\Convention\HandlerInjection;
use Semitexa\Dev\Ai\Convention\ModuleConventions;
use Semitexa\Dev\Generation\Builder\HandlerPlanBuilder;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;

class HandlerPlanBuilderTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . '/semitexa-handler-plan-test-' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function test_emits_vanilla_template_when_no_conventions_present(): void
    {
        $builder = new HandlerPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
            new ConventionStore($this->tmpRoot),
        );

        $plan = $builder->build([
            'module' => 'demo',
            'name' => 'create-user',
            'payload' => 'create-user',
            'resource' => 'create-user',
            'dryRun' => true,
        ]);

        $this->assertCount(1, $plan->files);
        $content = $plan->files[0]->content;

        $this->assertStringNotContainsString('#[InjectAsReadonly]', $content);
        $this->assertStringNotContainsString('#[InjectAsMutable]', $content);
        $this->assertStringNotContainsString('Semitexa\\Core\\Attribute\\InjectAs', $content);
        $this->assertStringContainsString('final class CreateUserHandler implements TypedHandlerInterface', $content);
        $this->assertStringContainsString('public function handle(CreateUserPayload $payload, CreateUserResponse $resource): CreateUserResponse', $content);
    }

    public function test_emits_conventional_injections_when_store_has_recurring_patterns(): void
    {
        $store = new ConventionStore($this->tmpRoot);
        $store->writeAll([
            'Demo' => new ModuleConventions(
                module: 'Demo',
                handler_injections: [
                    new HandlerInjection(
                        type: 'Acme\\Service\\CatalogService',
                        shortType: 'CatalogService',
                        attribute: 'InjectAsReadonly',
                        propertyName: 'catalog',
                        frequency: 7,
                    ),
                    new HandlerInjection(
                        type: 'Acme\\Service\\PresenterService',
                        shortType: 'PresenterService',
                        attribute: 'InjectAsReadonly',
                        propertyName: 'presenter',
                        frequency: 4,
                    ),
                ],
                handlers_sampled: 10,
            ),
        ]);

        $builder = new HandlerPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
            $store,
        );

        $plan = $builder->build([
            'module' => 'demo',
            'name' => 'create-user',
            'payload' => 'create-user',
            'resource' => 'create-user',
            'dryRun' => true,
        ]);

        $content = $plan->files[0]->content;

        $this->assertStringContainsString('use Acme\\Service\\CatalogService;', $content);
        $this->assertStringContainsString('use Acme\\Service\\PresenterService;', $content);
        $this->assertStringContainsString('use Semitexa\\Core\\Attribute\\InjectAsReadonly;', $content);

        $this->assertStringContainsString("    #[InjectAsReadonly]\n    protected CatalogService \$catalog;", $content);
        $this->assertStringContainsString("    #[InjectAsReadonly]\n    protected PresenterService \$presenter;", $content);

        $this->assertMatchesRegularExpression(
            '/protected PresenterService \$presenter;\s*\n\s*\n\s*public function handle\(/',
            $content,
        );
    }

    public function test_caps_injections_to_top_three_by_frequency(): void
    {
        $store = new ConventionStore($this->tmpRoot);
        $store->writeAll([
            'Demo' => new ModuleConventions(
                module: 'Demo',
                handler_injections: [
                    new HandlerInjection('Acme\\A', 'A', 'InjectAsReadonly', 'a', 9),
                    new HandlerInjection('Acme\\B', 'B', 'InjectAsReadonly', 'b', 8),
                    new HandlerInjection('Acme\\C', 'C', 'InjectAsReadonly', 'c', 7),
                    new HandlerInjection('Acme\\D', 'D', 'InjectAsReadonly', 'd', 6),
                ],
                handlers_sampled: 10,
            ),
        ]);

        $builder = new HandlerPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
            $store,
        );

        $content = $builder->build([
            'module' => 'demo',
            'name' => 'thing',
            'payload' => 'thing',
            'resource' => 'thing',
            'dryRun' => true,
        ])->files[0]->content;

        $this->assertStringContainsString('protected A $a;', $content);
        $this->assertStringContainsString('protected B $b;', $content);
        $this->assertStringContainsString('protected C $c;', $content);
        $this->assertStringNotContainsString('protected D $d;', $content);
    }

    public function test_omits_conventions_when_store_is_null(): void
    {
        $builder = new HandlerPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
            null,
        );

        $content = $builder->build([
            'module' => 'demo',
            'name' => 'thing',
            'payload' => 'thing',
            'resource' => 'thing',
            'dryRun' => true,
        ])->files[0]->content;

        $this->assertStringNotContainsString('#[InjectAsReadonly]', $content);
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
