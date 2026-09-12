<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Generation\Data\FileType;
use Semitexa\Dev\Application\Service\Generation\Data\PlannedFile;
use Semitexa\Dev\Application\Service\Generation\Writer\SafeFileWriter;

/**
 * The writer takes a path from a generator and joins it onto the project root.
 * It used to trust that path completely and to ignore what the filesystem said
 * back: `mkdir()` and `file_put_contents()` returned values were dropped, so a
 * read-only mount or a full disk still reported the file as `created`.
 *
 * A generator writing into a project is the one place where "it said it worked"
 * has to mean it. Everything here is about that: decide before touching disk,
 * write so a half-written file is never published, and say exactly what landed
 * when something goes wrong.
 */
final class SafeFileWriterPreflightTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/semitexa-dev-preflight-' . uniqid('', true);
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function file(string $path, string $content = '<?php echo 1;'): PlannedFile
    {
        return new PlannedFile($path, $content, FileType::PhpClass);
    }

    /** @return list<array{string, string}> */
    public static function refusedPaths(): array
    {
        return [
            'absolute'            => ['/etc/passwd', 'absolute'],
            'parent traversal'    => ['../escaped.php', 'traversal'],
            'traversal in middle' => ['src/../../escaped.php', 'traversal'],
            'current dir segment' => ['src/./thing.php', 'traversal'],
            'backslash'           => ['src\\thing.php', 'backslash'],
            'null byte'           => ["src/thing\0.php", 'control'],
            'newline'             => ["src/thing\n.php", 'control'],
            'empty'               => ['', 'empty'],
        ];
    }

    #[Test]
    #[DataProvider('refusedPaths')]
    public function a_refused_path_stops_the_whole_batch_before_anything_is_written(string $path, string $reason): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing');

        $result = $writer->write([
            $this->file('src/Good.php'),
            $this->file($path),
        ]);

        self::assertSame('rejected', $result->status, "'{$path}' must be refused");
        self::assertSame([], $result->created, 'a refused batch writes nothing at all');
        self::assertFileDoesNotExist($this->root . '/src/Good.php');
        self::assertNotNull($result->errors);
        self::assertStringContainsString($reason, strtolower(json_encode($result->errors, JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function a_write_through_a_symlinked_directory_is_refused(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside, 0777, true);
        mkdir($this->root . '/src', 0777, true);
        symlink($outside, $this->root . '/src/linked');

        try {
            $writer = new SafeFileWriter($this->root, 'make:thing');
            $result = $writer->write([$this->file('src/linked/Escaped.php')]);

            self::assertSame('rejected', $result->status);
            self::assertFileDoesNotExist($outside . '/Escaped.php', 'nothing may be written outside the project root');
        } finally {
            $this->removeTree($outside);
        }
    }

    #[Test]
    public function a_symlinked_target_is_refused_rather_than_written_through(): void
    {
        $outside = $this->root . '-outside';
        mkdir($outside, 0777, true);
        file_put_contents($outside . '/real.php', 'original');
        mkdir($this->root . '/src', 0777, true);
        symlink($outside . '/real.php', $this->root . '/src/Alias.php');

        try {
            $writer = new SafeFileWriter($this->root, 'make:thing');
            $result = $writer->write([$this->file('src/Alias.php')], force: true);

            self::assertSame('rejected', $result->status);
            self::assertSame('original', file_get_contents($outside . '/real.php'));
        } finally {
            $this->removeTree($outside);
        }
    }

    #[Test]
    public function conflicts_are_all_known_before_the_first_write(): void
    {
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Second.php', 'existing');

        $writer = new SafeFileWriter($this->root, 'make:thing');
        $result = $writer->write([
            $this->file('src/First.php'),
            $this->file('src/Second.php'),
        ]);

        self::assertSame('partial', $result->status);
        self::assertSame(['src/First.php'], $result->created);
        self::assertSame(['src/Second.php'], $result->conflicts);
        self::assertSame('existing', file_get_contents($this->root . '/src/Second.php'), 'a conflict is never overwritten without force');
    }

    #[Test]
    public function an_unwritable_directory_is_reported_not_claimed_as_created(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        mkdir($this->root . '/locked', 0500, true);

        $writer = new SafeFileWriter($this->root, 'make:thing');
        $result = $writer->write([$this->file('locked/Thing.php')]);

        self::assertNotSame('success', $result->status, 'a write that did not happen is not a success');
        self::assertSame([], $result->created);
        self::assertNotNull($result->errors, 'the caller must be told what the filesystem said');
    }

    #[Test]
    public function a_partial_failure_still_names_exactly_what_landed(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('running as root: permission bits are not enforced');
        }

        mkdir($this->root . '/locked', 0500, true);

        $writer = new SafeFileWriter($this->root, 'make:thing');
        $result = $writer->write([
            $this->file('ok/Fine.php'),
            $this->file('locked/Thing.php'),
        ]);

        self::assertSame(['ok/Fine.php'], $result->created, 'the created list is what is actually on disk');
        self::assertFileExists($this->root . '/ok/Fine.php');
        self::assertNotNull($result->errors);
        self::assertSame('partial', $result->status);
    }

    #[Test]
    public function no_half_written_file_is_ever_published(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing');
        $writer->write([$this->file('src/Thing.php', '<?php // complete')]);

        // Whatever temporary names the writer used must not survive the call.
        $leftovers = [];
        foreach (scandir($this->root . '/src') ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..' && $entry !== 'Thing.php') {
                $leftovers[] = $entry;
            }
        }

        self::assertSame([], $leftovers, 'temporary files must not be left behind');
        self::assertSame('<?php // complete', file_get_contents($this->root . '/src/Thing.php'));
    }

    #[Test]
    public function a_file_appearing_after_preflight_is_not_clobbered(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing');

        // Preflight saw nothing; the file exists by the time the write happens.
        mkdir($this->root . '/src', 0777, true);
        $planned = [$this->file('src/Race.php', 'generated')];
        file_put_contents($this->root . '/src/Race.php', 'written by someone else');

        $result = $writer->write($planned);

        self::assertSame(
            'written by someone else',
            file_get_contents($this->root . '/src/Race.php'),
            'without force the writer must create exclusively, never overwrite'
        );
        self::assertNotContains('src/Race.php', $result->created);
    }

    #[Test]
    public function force_replaces_the_file_and_reports_it(): void
    {
        mkdir($this->root . '/src', 0777, true);
        file_put_contents($this->root . '/src/Thing.php', 'old');

        $writer = new SafeFileWriter($this->root, 'make:thing');
        $result = $writer->write([$this->file('src/Thing.php', 'new')], force: true);

        self::assertSame('success', $result->status);
        self::assertSame(['src/Thing.php'], $result->created);
        self::assertSame('new', file_get_contents($this->root . '/src/Thing.php'));
    }

    #[Test]
    public function an_ordinary_nested_generation_still_works(): void
    {
        $writer = new SafeFileWriter($this->root, 'make:thing');
        $result = $writer->write([
            $this->file('src/modules/Demo/Application/Handler/Thing.php', '<?php class Thing {}'),
            $this->file('src/modules/Demo/README.md', '# Demo'),
        ]);

        self::assertSame('success', $result->status);
        self::assertCount(2, $result->created);
        self::assertNull($result->errors, 'a clean run carries no error field at all');
        self::assertFileExists($this->root . '/src/modules/Demo/Application/Handler/Thing.php');
    }
}
