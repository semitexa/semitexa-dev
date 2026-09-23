<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Trace;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Trace\ObservatoryLogReader;

/**
 * The reader, against real files including one larger than its own window.
 *
 * The bound is the reason this class exists, so it is tested with a file that
 * actually exceeds it rather than asserted in a comment. `app.log` reached
 * 380 MB on this host; a reader whose cost tracked the file would be unusable
 * exactly when someone needed it most.
 */
final class ObservatoryLogReaderTest extends TestCase
{
    private string $relative = '';
    private string|false $previousLogFile = false;
    private string $absolute = '';

    protected function setUp(): void
    {
        $this->relative = 'var/log/test-reader-' . bin2hex(random_bytes(6)) . '.log';
        $this->absolute = getcwd() . '/' . $this->relative;
        $this->previousLogFile = getenv('LOG_FILE');
        putenv('LOG_FILE=' . $this->relative);
    }

    protected function tearDown(): void
    {
        // Restore, not unset: the bootstrap points LOG_FILE at the test log, and an
        // unset here would send every later test's output back into app.log.
        putenv($this->previousLogFile === false ? 'LOG_FILE' : 'LOG_FILE=' . $this->previousLogFile);
        if (is_file($this->absolute)) {
            unlink($this->absolute);
        }
    }

    /** @param list<array<string, mixed>> $entries */
    private function write(array $entries, string $prefix = ''): void
    {
        $body = $prefix;
        foreach ($entries as $entry) {
            $body .= json_encode($entry) . "\n";
        }
        file_put_contents($this->absolute, $body);
    }

    private function entry(string $block, string $message, string $level = 'error'): array
    {
        return [
            'timestamp' => '2026-09-12T10:00:00+00:00',
            'level' => $level,
            'message' => $message,
            'context' => ['probe' => true],
            'block' => $block,
            'process' => 'p-17-abcdef',
        ];
    }

    #[Test]
    public function only_the_lines_written_in_that_block_come_back(): void
    {
        $this->write([
            $this->entry('pipeline.handler', 'the handler complained'),
            $this->entry('auth.pre_hydration_gate', 'the gate complained'),
            $this->entry('pipeline.handler', 'the handler complained again'),
        ]);

        $lines = (new ObservatoryLogReader())->forBlock('pipeline.handler');

        self::assertCount(2, $lines);
        self::assertSame('the handler complained', $lines[0]['message']);
        self::assertSame('p-17-abcdef', $lines[0]['process']);
    }

    #[Test]
    public function matching_is_exact_and_never_widens(): void
    {
        // 'pipeline.handler' must not collect 'pipeline.handler_completed'.
        // These are adjacent stages in the picture, so a substring rule would
        // put one stage's lines under its neighbour and look plausible.
        $this->write([
            $this->entry('pipeline.handler_completed', 'the completed hook complained'),
        ]);

        self::assertSame([], (new ObservatoryLogReader())->forBlock('pipeline.handler'));
    }

    #[Test]
    public function lines_with_no_block_belong_to_no_block(): void
    {
        $this->write([
            ['timestamp' => '2026-09-12T10:00:00+00:00', 'level' => 'warning', 'message' => 'worker is starting'],
        ]);

        self::assertSame([], (new ObservatoryLogReader())->forBlock('pipeline.handler'));
    }

    #[Test]
    public function an_empty_block_asks_for_nothing_rather_than_everything(): void
    {
        $this->write([$this->entry('pipeline.handler', 'the handler complained')]);

        self::assertSame([], (new ObservatoryLogReader())->forBlock(''));
    }

    #[Test]
    public function a_file_larger_than_the_window_is_read_from_its_end(): void
    {
        // One unique marker at the very TOP of the file, then 700 KB of noise,
        // then the line we want. A reader that scanned the whole file would
        // return the marker too — it must not, because scanning is the
        // behaviour that does not survive a 380 MB file.
        $marker = json_encode($this->entry('pipeline.handler', 'buried far above the window')) . "\n";
        $noise = json_encode($this->entry('pipeline.listener', 'noise under another block')) . "\n";
        $this->write(
            [$this->entry('pipeline.handler', 'inside the window')],
            $marker . str_repeat($noise, (int) ceil(700_000 / strlen($noise))),
        );

        self::assertGreaterThan(512_000, (int) filesize($this->absolute), 'the fixture must exceed the window');

        $lines = (new ObservatoryLogReader())->forBlock('pipeline.handler');
        $messages = array_column($lines, 'message');

        self::assertSame(['inside the window'], $messages, 'only the tail of the file is read');
        self::assertNotContains(
            'buried far above the window',
            $messages,
            'the window is anchored to the end of the file, not the start',
        );
    }

    #[Test]
    public function the_partial_line_the_window_opens_on_is_dropped(): void
    {
        // Seeking to size-WINDOW lands mid-line almost every time. That
        // fragment is not an entry and decoding it would be a parse error per
        // read, or worse, a half-entry treated as real.
        $noise = json_encode($this->entry('pipeline.handler', 'noise')) . "\n";
        $this->write([$this->entry('pipeline.handler', 'the last word')], str_repeat($noise, (int) ceil(600_000 / strlen($noise))));

        $lines = (new ObservatoryLogReader())->forBlock('pipeline.handler');

        self::assertNotSame([], $lines);
        self::assertSame('the last word', end($lines)['message'], 'newest line survives the truncation');
    }

    #[Test]
    public function one_chatty_block_cannot_flood_the_panel(): void
    {
        $entries = [];
        for ($i = 0; $i < 120; $i++) {
            $entries[] = $this->entry('pipeline.handler', 'line ' . $i);
        }
        $this->write($entries);

        $lines = (new ObservatoryLogReader())->forBlock('pipeline.handler', 500);

        self::assertCount(40, $lines, 'the caller does not get to raise the ceiling');
        self::assertSame('line 119', end($lines)['message'], 'and what survives the cap is the NEWEST');
    }

    #[Test]
    public function a_missing_log_file_is_not_an_error(): void
    {
        // A project that has never logged anything is a normal state, not a
        // failure the panel should report.
        self::assertSame([], (new ObservatoryLogReader())->forBlock('pipeline.handler'));
    }
}
