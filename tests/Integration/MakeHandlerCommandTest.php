<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Builder\HandlerPlanBuilder;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;

class MakeHandlerCommandTest extends TestCase
{
    public function test_generates_valid_handler(): void
    {
        $builder = new HandlerPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'Pricing',
            'payload' => 'Pricing',
            'resource' => 'Pricing',
            'dryRun' => false,
        ]);

        $this->assertCount(1, $plan->files);
        $file = $plan->files[0];
        $this->assertSame('src/modules/Website/Application/Handler/PayloadHandler/PricingHandler.php', $file->path);
        $this->assertStringContainsString('final class PricingHandler implements TypedHandlerInterface', $file->content);
        $this->assertStringContainsString('#[AsPayloadHandler(payload: PricingPayload::class, resource: PricingResponse::class)]', $file->content);
        $this->assertStringContainsString('public function handle(PricingPayload $payload, PricingResponse $resource): PricingResponse', $file->content);
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
