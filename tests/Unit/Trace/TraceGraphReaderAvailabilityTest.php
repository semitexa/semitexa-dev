<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\TraceGraphReader;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;

/**
 * "No graph yet" and "a graph that cannot be read" are different answers,
 * judged against real SQLite files: an unbuilt graph opens as an empty
 * database whose first read finds no table; a corrupt file fails otherwise.
 */
final class TraceGraphReaderAvailabilityTest extends TestCase
{
    private string $file;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('DB_PROJECT_GRAPH_SQLITE_PATH');
        $this->file = sys_get_temp_dir() . '/graph-availability-' . bin2hex(random_bytes(4)) . '.sqlite';
        putenv('DB_PROJECT_GRAPH_DRIVER=sqlite');
        putenv('DB_PROJECT_GRAPH_SQLITE_PATH=' . $this->file);
    }

    protected function tearDown(): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->file . $suffix);
        }
        putenv('DB_PROJECT_GRAPH_DRIVER');
        putenv($this->previous === false ? 'DB_PROJECT_GRAPH_SQLITE_PATH' : 'DB_PROJECT_GRAPH_SQLITE_PATH=' . $this->previous);
    }

    #[Test]
    public function an_unbuilt_graph_is_missing(): void
    {
        $reader = $this->reader();

        self::assertNull($reader->storage());
        self::assertTrue($reader->isMissing());
    }

    #[Test]
    public function a_corrupt_graph_file_is_not_reported_as_missing(): void
    {
        file_put_contents($this->file, str_repeat("not a database\0", 512));
        $reader = $this->reader();

        self::assertNull($reader->storage());
        self::assertFalse($reader->isMissing());
    }

    private function reader(): TraceGraphReader
    {
        $reader = new TraceGraphReader();
        (new \ReflectionProperty($reader, 'connections'))->setValue($reader, new ConnectionRegistry());

        return $reader;
    }
}
