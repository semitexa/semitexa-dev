<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Builder\PayloadPlanBuilder;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;
use Semitexa\Dev\Generation\Writer\SafeFileWriter;

class ForceOverwriteTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/semitexa-dev-force-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_force_overwrites_existing(): void
    {
        $builder = new PayloadPlanBuilder(
            new NameInflector(),
            new TemplateResolver(),
            new TemplateRenderer(),
        );

        $plan = $builder->build([
            'module' => 'Website',
            'name' => 'ForceTest',
            'path' => '/force-test',
            'method' => 'GET',
            'response' => 'ForceTest',
            'public' => false,
            'dryRun' => false,
        ]);

        $writer = new SafeFileWriter($this->tmpDir, 'make:payload');

        // First write
        $result1 = $writer->write($plan->files);
        $this->assertSame('success', $result1->status);

        // Second write without force — conflict
        $result2 = $writer->write($plan->files);
        $this->assertSame('conflict', $result2->status);

        // Third write with force — success
        $result3 = $writer->write($plan->files, force: true);
        $this->assertSame('success', $result3->status);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
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
