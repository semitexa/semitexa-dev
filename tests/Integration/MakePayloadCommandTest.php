<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Builder\PayloadPlanBuilder;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;

class MakePayloadCommandTest extends TestCase
{
    public function test_generates_valid_payload(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'path' => '/pricing',
            'method' => 'GET',
            'response' => 'Pricing',
            'public' => true,
            'dryRun' => false,
        ]);

        $this->assertCount(1, $plan->files);
        $file = $plan->files[0];
        $this->assertSame('src/modules/Website/Application/Payload/Request/PricingPayload.php', $file->path);
        $this->assertStringContainsString('declare(strict_types=1);', $file->content);
        $this->assertStringContainsString('#[AsPayload(', $file->content);
        $this->assertStringContainsString("path: '/pricing'", $file->content);
        $this->assertStringContainsString("methods: ['GET']", $file->content);
        $this->assertStringContainsString('PricingResponse::class', $file->content);
        $this->assertStringContainsString('#[PublicEndpoint]', $file->content);
        $this->assertStringContainsString('implements ValidatablePayload', $file->content);
        $this->assertPhpSyntaxValid($file->content);
    }

    public function test_generates_non_public_post_payload(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'ContactForm',
            'path' => '/contact',
            'method' => 'POST',
            'response' => 'ContactForm',
            'public' => false,
            'dryRun' => false,
        ]);

        $file = $plan->files[0];
        $this->assertStringNotContainsString('PublicEndpoint', $file->content);
        $this->assertStringContainsString("methods: ['POST']", $file->content);
        $this->assertPhpSyntaxValid($file->content);
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
