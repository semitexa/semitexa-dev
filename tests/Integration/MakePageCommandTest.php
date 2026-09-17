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

    /**
     * The generated page script has to survive markup that arrives later.
     *
     * The obvious thing to write is a `<script>` in the page template, and it
     * works — until the page becomes swappable, at which point it is inert
     * until re-created, then runs once per arrival, and has already missed
     * DOMContentLoaded. The scaffold is where that lesson is cheapest to
     * teach, so it ships the shape instead of describing it.
     */
    public function test_generated_page_script_binds_idempotently_and_not_on_domcontentloaded(): void
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
            'withAssets' => true,
            'withTest' => false,
            'dryRun' => false,
        ]);

        $js = '';
        $twig = '';
        foreach ($plan->files as $file) {
            if (str_ends_with($file->path, 'pages/pricing.js')) {
                $js = $file->content;
            }
            if (str_ends_with($file->path, 'pages/pricing.html.twig')) {
                $twig = $file->content;
            }
        }

        self::assertNotSame('', $js, 'the page script must be generated');
        // The docblock NAMES that event — explaining why it is the wrong hook
        // is half the point of the scaffold. What must not appear is code
        // waiting on it.
        self::assertStringNotContainsString("addEventListener('DOMContentLoaded'", $js);
        self::assertStringNotContainsString('addEventListener("DOMContentLoaded"', $js);
        self::assertStringContainsString('MutationObserver', $js, 'markup that arrives later has to be connected too');
        self::assertStringContainsString('data-pricing-bound', $js, 'binding twice is the failure this guards');
        self::assertStringContainsString('AsUiBehavior', $js, 'the scaffold names the mechanism that solves this outright');

        // Asserted non-empty FIRST: the negative below is satisfied by a
        // template that was never generated, so a rename of the page file
        // would turn this into a test that passes on nothing.
        self::assertNotSame('', $twig, 'the page template must be generated');
        self::assertStringNotContainsString('<script', $twig, 'the template must not teach the inline shape');
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
