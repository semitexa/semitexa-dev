<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\PagePlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

class MakePageCommandTest extends TestCase
{
    public function test_generates_full_page_scaffold(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => false,
            'dryRun' => false,
        ]);

        // The all-in-one ships tested by default:
        // Payload + Handler + Resource + Template + PayloadContractTest + HandlerTest = 6 files
        $this->assertCount(6, $plan->files);

        $paths = array_map(fn($f) => $f->path, $plan->files);
        $this->assertContains('src/modules/Website/src/Application/Payload/Request/PricingPayload.php', $paths);
        $this->assertContains('src/modules/Website/src/Application/Handler/PayloadHandler/PricingHandler.php', $paths);
        $this->assertContains('src/modules/Website/src/Application/Resource/Response/PricingResponse.php', $paths);
        $this->assertContains('src/modules/Website/src/Application/View/templates/pages/pricing.html.twig', $paths);
        $this->assertContains('src/modules/Website/tests/Integration/PricingPayloadContractTest.php', $paths);
        $this->assertContains('src/modules/Website/tests/Unit/PricingHandlerTest.php', $paths);

        // Validate PHP syntax for all PHP files
        foreach ($plan->files as $file) {
            if (str_ends_with($file->path, '.php')) {
                $this->assertPhpSyntaxValid($file->content);
            }
        }
    }

    public function test_no_test_flag_omits_the_test_scaffolds(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'public',
            'withAssets' => false,
            'withTest' => false,
            'dryRun' => false,
        ]);

        $this->assertCount(4, $plan->files);
        $paths = array_map(fn($f) => $f->path, $plan->files);
        $this->assertNotContains('src/modules/Website/tests/Integration/PricingPayloadContractTest.php', $paths);
        $this->assertNotContains('src/modules/Website/tests/Unit/PricingHandlerTest.php', $paths);
    }

    public function test_generates_page_with_assets(): void
    {
        $builder = new PagePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'access' => 'protected',
            'withAssets' => true,
            'withTest' => false,
            'dryRun' => false,
        ]);

        // Payload + Handler + Resource + Template + assets.json + CSS + JS = 7
        $this->assertCount(7, $plan->files);

        $paths = array_map(fn($f) => $f->path, $plan->files);
        $this->assertContains('src/modules/Website/src/Application/View/assets/pages/pricing.json', $paths);
        $this->assertContains('src/modules/Website/src/Application/View/assets/pages/pricing.css', $paths);
        $this->assertContains('src/modules/Website/src/Application/View/assets/pages/pricing.js', $paths);
    }

    private function assertPhpSyntaxValid(string $code): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'php_lint_');
        file_put_contents($tmp, $code);
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $output, $code);
        unlink($tmp);
        $this->assertSame(0, $code, 'PHP syntax error: ' . implode("\n", $output));
    }
}
