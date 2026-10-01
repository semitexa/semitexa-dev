<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\PhaseSummary;
use Semitexa\Dev\Application\Service\Trace\TraceClassIndex;

/**
 * From a graph node back to the recorded requests that ran its class.
 *
 * The rules these pin down: the journal end line carries the classes a
 * request's spans named, deduplicated and kept small enough that the line is
 * never dropped; and the lookup returns newest first, only persisted traces,
 * only an exact class — a prefix of another class is not a match.
 */
final class TraceClassIndexTest extends TestCase
{
    private string $dir;
    private string|false $previousDir;

    protected function setUp(): void
    {
        $this->previousDir = getenv('SEMITEXA_OBSERVATORY_DIR');
        $this->dir = sys_get_temp_dir() . '/obs-idx-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
        putenv($this->previousDir === false ? 'SEMITEXA_OBSERVATORY_DIR' : 'SEMITEXA_OBSERVATORY_DIR=' . $this->previousDir);
    }

    #[Test]
    public function the_end_line_names_each_span_class_once(): void
    {
        $classes = PhaseSummary::classes([
            ['type' => 'begin', 'name' => 'request', 'context' => ['path' => '/']],
            ['type' => 'begin', 'name' => 'pipeline.listener', 'context' => ['listener' => 'App\\Listener\\One']],
            ['type' => 'begin', 'name' => 'pipeline.listener', 'context' => ['listener' => 'App\\Listener\\One']],
            ['type' => 'begin', 'name' => 'pipeline.handler', 'context' => ['handler' => 'App\\Handler\\Home']],
            ['type' => 'end', 'name' => 'pipeline.handler', 'context' => ['handler' => 'App\\Handler\\Ignored']],
        ]);

        self::assertSame(['App\\Listener\\One', 'App\\Handler\\Home'], $classes);
    }

    #[Test]
    public function a_huge_request_cannot_push_the_line_over_the_journal_cap(): void
    {
        $events = [];
        for ($i = 0; $i < 500; $i++) {
            $events[] = ['type' => 'begin', 'name' => 'x', 'context' => ['handler' => 'App\\Some\\Rather\\Long\\Namespace\\Klass' . $i]];
        }

        $classes = PhaseSummary::classes($events);

        self::assertNotSame([], $classes);
        self::assertLessThan(2000, strlen((string) json_encode($classes)));
    }

    #[Test]
    public function newest_persisted_traces_that_name_the_exact_class(): void
    {
        $this->journal('20260930', [
            $this->end('old.json', ['App\\Handler\\Home']),
        ]);
        $this->journal('20261001', [
            $this->end('a.json', ['App\\Handler\\Home']),
            $this->end('prefix.json', ['App\\Handler\\HomeExtra']),
            ['event' => 'end', 'id' => 'p', 'kind' => 'http', 'name' => 'x', 'classes' => ['App\\Handler\\Home']], // no trace file
            $this->end('b.json', ['App\\Handler\\Home', 'App\\Other']),
        ]);

        $found = (new TraceClassIndex())->forClass('App\\Handler\\Home');

        self::assertSame(['b.json', 'a.json', 'old.json'], array_column($found, 'trace'));
        self::assertSame(['b.json'], array_column((new TraceClassIndex())->forClass('App\\Handler\\Home', 1), 'trace'));
        self::assertSame([], (new TraceClassIndex())->forClass('App\\Nothing'));
    }

    /** @param list<string> $classes @return array<string, mixed> */
    private function end(string $trace, array $classes): array
    {
        return ['ts' => '2026-10-01T00:00:00+00:00', 'event' => 'end', 'id' => 'p-' . $trace, 'kind' => 'http', 'name' => 'Payload', 'durationMs' => 1.5, 'trace' => $trace, 'classes' => $classes];
    }

    /** @param list<array<string, mixed>> $rows */
    private function journal(string $day, array $rows): void
    {
        file_put_contents(
            $this->dir . '/journal-' . $day . '.ndjson',
            implode("\n", array_map(static fn (array $r): string => (string) json_encode($r), $rows)) . "\n",
        );
    }
}
