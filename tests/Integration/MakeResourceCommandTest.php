<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Builder\ResourcePlanBuilder;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;

class MakeResourceCommandTest extends TestCase
{
    public function test_generates_valid_resource(): void
    {
        $builder = new ResourcePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'handle' => 'pricing',
            'template' => null,
            'withTemplate' => false,
            'withAssets' => false,
            'dryRun' => false,
        ]);

        $this->assertCount(1, $plan->files);
        $file = $plan->files[0];
        $this->assertSame('src/modules/Website/Application/Resource/Response/PricingResponse.php', $file->path);
        $this->assertStringContainsString("handle: 'pricing'", $file->content);
        $this->assertStringContainsString('extends HtmlResponse implements ResourceInterface', $file->content);
        $this->assertPhpSyntaxValid($file->content);
    }

    public function test_generates_resource_with_template(): void
    {
        $builder = new ResourcePlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'handle' => 'pricing',
            'template' => null,
            'withTemplate' => true,
            'withAssets' => false,
            'dryRun' => false,
        ]);

        $this->assertCount(2, $plan->files);
        $this->assertStringContainsString('.html.twig', $plan->files[1]->path);
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
