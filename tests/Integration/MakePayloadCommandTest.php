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
        $this->assertSame('src/modules/Website/src/Application/Payload/Request/PricingPayload.php', $file->path);
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

    public function test_graphql_flag_emits_bare_expose_as_graphql_marker(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Shop',
            'name' => 'ProductBySlug',
            'path' => '/products/{slug}',
            'method' => 'GET',
            'response' => 'Product',
            'access' => PayloadPlanBuilder::ACCESS_PUBLIC,
            'graphql' => true,
            'dryRun' => false,
        ]);

        $content = $plan->files[0]->content;
        // Bare marker — field/rootType/output all derive, so no params are emitted.
        $this->assertStringContainsString("#[ExposeAsGraphql]\n", $content);
        $this->assertStringNotContainsString('#[ExposeAsGraphql(', $content);
        $this->assertStringContainsString('use Semitexa\\Graphql\\Attribute\\ExposeAsGraphql;', $content);
        // Marker sits directly above the class with no blank gap.
        $this->assertStringContainsString("#[ExposeAsGraphql]\nclass ProductBySlugPayload", $content);
        $this->assertPhpSyntaxValid($content);
    }

    public function test_graphql_field_override_is_emitted_when_given(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Shop',
            'name' => 'ProductBySlug',
            'path' => '/products/{slug}',
            'method' => 'GET',
            'response' => 'Product',
            'access' => PayloadPlanBuilder::ACCESS_PUBLIC,
            'graphql' => true,
            'graphqlField' => 'productLookup',
            'dryRun' => false,
        ]);

        $content = $plan->files[0]->content;
        $this->assertStringContainsString("#[ExposeAsGraphql(field: 'productLookup')]", $content);
        $this->assertPhpSyntaxValid($content);
    }

    public function test_no_graphql_marker_without_the_flag(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Shop',
            'name' => 'ProductBySlug',
            'path' => '/products/{slug}',
            'method' => 'GET',
            'response' => 'Product',
            'access' => PayloadPlanBuilder::ACCESS_PUBLIC,
            'dryRun' => false,
        ]);

        $content = $plan->files[0]->content;
        $this->assertStringNotContainsString('ExposeAsGraphql', $content);
        // The placeholder collapses cleanly: route attribute is followed directly
        // by the class line, no stray blank line.
        $this->assertStringContainsString(")]\nclass ProductBySlugPayload", $content);
        $this->assertPhpSyntaxValid($content);
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
