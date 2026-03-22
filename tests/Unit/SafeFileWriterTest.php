<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Generation\Data\FileType;
use Semitexa\Dev\Generation\Data\PlannedFile;
use Semitexa\Dev\Generation\Writer\SafeFileWriter;

class SafeFileWriterTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/semitexa-dev-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function test_creates_new_file(): void
    {
        $writer = new SafeFileWriter($this->tmpDir, 'test');
        $files = [new PlannedFile('sub/dir/test.php', '<?php echo 1;', FileType::PhpClass)];

        $result = $writer->write($files);

        $this->assertSame('success', $result->status);
        $this->assertSame(['sub/dir/test.php'], $result->created);
        $this->assertFileExists($this->tmpDir . '/sub/dir/test.php');
    }

    public function test_detects_conflict(): void
    {
        mkdir($this->tmpDir . '/existing', 0755, true);
        file_put_contents($this->tmpDir . '/existing/file.php', 'old');

        $writer = new SafeFileWriter($this->tmpDir, 'test');
        $files = [new PlannedFile('existing/file.php', 'new', FileType::PhpClass)];

        $result = $writer->write($files);

        $this->assertSame('conflict', $result->status);
        $this->assertSame(['existing/file.php'], $result->conflicts);
        $this->assertSame('old', file_get_contents($this->tmpDir . '/existing/file.php'));
    }

    public function test_force_overwrites(): void
    {
        mkdir($this->tmpDir . '/existing', 0755, true);
        file_put_contents($this->tmpDir . '/existing/file.php', 'old');

        $writer = new SafeFileWriter($this->tmpDir, 'test');
        $files = [new PlannedFile('existing/file.php', 'new', FileType::PhpClass)];

        $result = $writer->write($files, force: true);

        $this->assertSame('success', $result->status);
        $this->assertSame(['existing/file.php'], $result->created);
        $this->assertSame('new', file_get_contents($this->tmpDir . '/existing/file.php'));
    }

    public function test_partial_when_some_conflict(): void
    {
        mkdir($this->tmpDir . '/dir', 0755, true);
        file_put_contents($this->tmpDir . '/dir/existing.php', 'old');

        $writer = new SafeFileWriter($this->tmpDir, 'test');
        $files = [
            new PlannedFile('dir/existing.php', 'new', FileType::PhpClass),
            new PlannedFile('dir/new.php', 'content', FileType::PhpClass),
        ];

        $result = $writer->write($files);

        $this->assertSame('partial', $result->status);
        $this->assertSame(['dir/new.php'], $result->created);
        $this->assertSame(['dir/existing.php'], $result->conflicts);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
