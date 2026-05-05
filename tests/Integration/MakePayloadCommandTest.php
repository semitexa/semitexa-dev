<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\PayloadPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

class MakePayloadCommandTest extends TestCase
{
    public function test_generates_public_payload_with_AsPublicPayload_attribute(): void
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
            'access' => PayloadPlanBuilder::ACCESS_PUBLIC,
            'dryRun' => false,
        ]);

        $this->assertCount(1, $plan->files);
        $file = $plan->files[0];
        $this->assertSame('src/modules/Website/Application/Payload/Request/PricingPayload.php', $file->path);
        $this->assertStringContainsString('declare(strict_types=1);', $file->content);
        $this->assertStringContainsString('#[AsPublicPayload(', $file->content);
        $this->assertStringContainsString('use Semitexa\\Core\\Attribute\\AsPublicPayload;', $file->content);
        $this->assertStringNotContainsString('AsProtectedPayload', $file->content);
        $this->assertStringNotContainsString('AsServicePayload', $file->content);
        $this->assertStringNotContainsString('PublicEndpoint', $file->content);
        $this->assertStringNotContainsString('use Semitexa\\Core\\Attribute\\AsPayload', $file->content);
        $this->assertStringContainsString("path: '/pricing'", $file->content);
        $this->assertStringContainsString("methods: ['GET']", $file->content);
        $this->assertStringContainsString('PricingResponse::class', $file->content);
        $this->assertStringNotContainsString('ValidatablePayload', $file->content);
        $this->assertStringNotContainsString('PayloadValidationResult', $file->content);
        $this->assertStringNotContainsString('public function validate(', $file->content);
        $this->assertPhpSyntaxValid($file->content);
    }

    public function test_generates_protected_payload_with_AsProtectedPayload_attribute(): void
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
            'access' => PayloadPlanBuilder::ACCESS_PROTECTED,
            'dryRun' => false,
        ]);

        $file = $plan->files[0];
        $this->assertStringContainsString('#[AsProtectedPayload(', $file->content);
        $this->assertStringContainsString('use Semitexa\\Authorization\\Attribute\\AsProtectedPayload;', $file->content);
        $this->assertStringNotContainsString('AsPublicPayload', $file->content);
        $this->assertStringNotContainsString('AsServicePayload', $file->content);
        $this->assertStringNotContainsString('PublicEndpoint', $file->content);
        $this->assertStringContainsString("methods: ['POST']", $file->content);
        $this->assertPhpSyntaxValid($file->content);
    }

    public function test_generates_service_payload_with_AsServicePayload_attribute(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'WebhookReceive',
            'path' => '/webhooks/incoming',
            'method' => 'POST',
            'response' => 'WebhookReceive',
            'access' => PayloadPlanBuilder::ACCESS_SERVICE,
            'dryRun' => false,
        ]);

        $file = $plan->files[0];
        $this->assertStringContainsString('#[AsServicePayload(', $file->content);
        $this->assertStringContainsString('use Semitexa\\Authorization\\Attribute\\AsServicePayload;', $file->content);
        $this->assertStringNotContainsString('AsPublicPayload', $file->content);
        $this->assertStringNotContainsString('AsProtectedPayload', $file->content);
        $this->assertPhpSyntaxValid($file->content);
    }

    public function test_unknown_access_type_is_rejected(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown payload access type "open"/');

        $builder->build([
            'module' => 'Website',
            'name' => 'Bad',
            'path' => '/bad',
            'method' => 'GET',
            'response' => 'Bad',
            'access' => 'open',
            'dryRun' => false,
        ]);
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
