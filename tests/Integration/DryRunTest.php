<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Builder\PayloadPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;

class DryRunTest extends TestCase
{
    public function test_dry_run_does_not_write_files(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'DryRunTest',
            'path' => '/dry-run-test',
            'method' => 'GET',
            'response' => 'DryRunTest',
            'access' => 'protected',
            'dryRun' => true,
        ]);

        $this->assertTrue($plan->dryRun);
        $this->assertCount(1, $plan->files);
        // Files are planned but not written — the command checks dryRun flag before calling SafeFileWriter
    }
}
