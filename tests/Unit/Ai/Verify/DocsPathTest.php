<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\DocsPath;

/** ai:verify's hints name a docs page that exists in this project, not the workspace's path. */
final class DocsPathTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-docs-path-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    #[Test]
    public function a_consumer_project_gets_the_installed_page(): void
    {
        $this->page('vendor/semitexa/docs/docs/AI_BEST_PRACTICES.md');

        self::assertSame('vendor/semitexa/docs/docs/AI_BEST_PRACTICES.md', DocsPath::of($this->root, 'AI_BEST_PRACTICES.md'));
    }

    #[Test]
    public function the_workspace_checkout_comes_before_the_installed_package(): void
    {
        $this->page('vendor/semitexa/docs/docs/MODULE_STRUCTURE.md');
        $this->page('packages/semitexa-docs/docs/MODULE_STRUCTURE.md');

        self::assertSame('packages/semitexa-docs/docs/MODULE_STRUCTURE.md', DocsPath::of($this->root . '/', 'MODULE_STRUCTURE.md'));
    }

    #[Test]
    public function a_page_that_is_nowhere_is_named_nowhere(): void
    {
        $this->page('vendor/semitexa/docs/docs/OTHER.md');

        self::assertNull(DocsPath::of($this->root, 'AI_BEST_PRACTICES.md'));
    }

    private function page(string $relative): void
    {
        $path = $this->root . '/' . $relative;
        mkdir(dirname($path), 0755, true);
        file_put_contents($path, "# page\n");
    }
}
