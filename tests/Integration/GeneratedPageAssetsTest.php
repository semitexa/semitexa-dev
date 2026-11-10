<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Application\Service\Generation\Builder\PagePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\ResourcePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationPlan;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

/**
 * What --with-assets writes is what the runtime reads and ai:verify accepts
 * (tk-guard-make-assets-legacy-path).
 *
 * make:page and make:resource wrote View/assets/pages/<page>.{json,css,js}.
 * Nothing read that directory, so the page never got its files, and the
 * module_structure check refused it on the next ai:verify. Assets live in
 * Application/Static under one v2 assets.json per module.
 */
final class GeneratedPageAssetsTest extends TestCase
{
    private string $root;
    private ?string $cwd = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-page-assets-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
        file_put_contents($this->root . '/composer.json', '{"name":"temp/project"}');
        $this->cwd = getcwd() ?: null;
        chdir($this->root);
        ProjectRoot::reset();
    }

    protected function tearDown(): void
    {
        if ($this->cwd !== null) {
            chdir($this->cwd);
        }
        ProjectRoot::reset();
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    #[Test]
    public function a_page_with_assets_passes_the_module_structure_check(): void
    {
        $this->write($this->page());

        $spec = (new ModuleStructureSpecLoader(dirname(__DIR__, 4)))->load();
        $violations = (new ModuleStructureValidator($this->root, $spec))->validate(
            new DetectedModule(name: 'Website', relativePath: 'src/modules/Website', kind: DetectedModule::KIND_APPLICATION),
        );

        self::assertFileExists($this->root . '/src/modules/Website/src/Application/Static/js/pricing.js', 'the tree under check holds the assets');
        self::assertSame([], array_map(static fn ($v): string => $v->code . ' ' . $v->path, $violations));
    }

    #[Test]
    public function the_manifest_is_v2_and_the_page_asks_for_its_script(): void
    {
        $files = $this->contents($this->page());

        $manifest = json_decode($files['src/modules/Website/src/Application/Static/assets.json'] ?? '', true);
        self::assertIsArray($manifest);
        self::assertSame('semitexa://asset-manifest/v2', $manifest['$schema']);
        self::assertSame('Website', $manifest['module']);
        // The registry finds files by these rules: css/pricing.css and js/pricing.js match them.
        self::assertSame(['css' => ['css/**/*.css'], 'js' => ['js/**/*.js']], $manifest['include']);

        // A script is page-scoped, so the page asks for it by the key the registry derives.
        $twig = $files['src/modules/Website/src/Application/View/templates/pages/pricing.html.twig'] ?? '';
        self::assertStringContainsString("{{ asset_require('Website:js:pricing') }}", $twig);
        self::assertStringContainsString('{% block main %}', $twig);
    }

    #[Test]
    public function a_module_that_has_a_manifest_keeps_it(): void
    {
        $manifest = $this->root . '/src/modules/Website/src/Application/Static/assets.json';
        mkdir(dirname($manifest), 0755, true);
        file_put_contents($manifest, '{"$schema":"semitexa://asset-manifest/v2","module":"Website","include":{"css":["css/*.css"]}}');

        $paths = array_keys($this->contents($this->page()));

        self::assertNotContains('src/modules/Website/src/Application/Static/assets.json', $paths, 'the module\'s manifest is not replaced');
        self::assertContains('src/modules/Website/src/Application/Static/css/pricing.css', $paths);
    }

    #[Test]
    public function make_resource_writes_the_same_place(): void
    {
        $plan = (new ResourcePlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'handle' => 'pricing',
            'withTemplate' => false,
            'withAssets' => true,
            'dryRun' => false,
        ]);

        $paths = array_keys($this->contents($plan));
        self::assertContains('src/modules/Website/src/Application/Static/assets.json', $paths);
        self::assertContains('src/modules/Website/src/Application/Static/css/pricing.css', $paths);
        self::assertContains('src/modules/Website/src/Application/Static/js/pricing.js', $paths);
        self::assertSame([], array_values(array_filter($paths, static fn (string $p): bool => str_contains($p, '/View/assets/'))));
    }

    private function page(): GenerationPlan
    {
        return (new PagePlanBuilder(new NameInflector(), new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => true,
            'withTest' => false,
            'dryRun' => false,
        ]);
    }

    /** @return array<string, string> */
    private function contents(GenerationPlan $plan): array
    {
        $files = [];
        foreach ($plan->files as $file) {
            $files[$file->path] = $file->content;
        }

        return $files;
    }

    private function write(GenerationPlan $plan): void
    {
        foreach ($plan->files as $file) {
            $path = $this->root . '/' . $file->path;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $file->content);
        }
    }
}
