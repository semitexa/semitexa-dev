<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Trace;

use PHPUnit\Framework\Attributes\Test;
use Semitexa\Dev\Application\Service\Trace\ObservatoryJournal;
use Semitexa\Testing\TestCase;

/**
 * The journal keeps its append handle open for the worker instead of opening
 * and closing the file for every line.
 *
 * Opening was the whole cost. Measured on the bind-mounted var/observatory,
 * back to back in one process: `file_put_contents(FILE_APPEND|LOCK_EX)` 5.29us
 * per line against 1.40us for a kept-open handle — 3.8x — and a root request
 * span writes two lines, begin and end.
 *
 * Three things have to survive that, and each is easy to lose:
 *
 *  1. One write is still one line, and flock still makes it atomic across
 *     workers.
 *  2. A line is readable WITHOUT the writer closing the file. The panel reads
 *     the journal live, so a line sitting in a PHP stream buffer is a line the
 *     live view does not have.
 *  3. The handle follows the PATH, not the day. A test that puts a fresh
 *     SEMITEXA_OBSERVATORY_DIR in the environment changes the path without
 *     changing the day — a handle keyed on the day would keep writing into the
 *     previous directory's file.
 */
final class ObservatoryJournalWriteTest extends TestCase
{
    private string $dir;

    /** @var array<string, string|false> */
    private array $previousEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        // SAVED, not assumed unset. Blanking them in tearDown would hand every
        // later test a different configuration from the one the process started
        // with, which is the kind of leak that surfaces as an unrelated failure
        // three files away.
        foreach (['APP_ENV', 'SEMITEXA_OBSERVATORY_DIR'] as $key) {
            $this->previousEnv[$key] = getenv($key);
        }

        $this->dir = sys_get_temp_dir() . '/semitexa-journal-' . uniqid('', true);
        mkdir($this->dir, 0755, true);
        putenv('APP_ENV=dev');
        putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv($key . '=' . $value);
            }
        }
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function journalPath(?string $dir = null): string
    {
        return ($dir ?? $this->dir) . '/journal-' . date('Ymd') . '.ndjson';
    }

    /** One write, one line — and it is valid NDJSON. */
    #[Test]
    public function each_write_appends_exactly_one_line(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-1']);
        ObservatoryJournal::write(['event' => 'end', 'id' => 'p-1']);

        $lines = array_values(array_filter(file($this->journalPath(), FILE_IGNORE_NEW_LINES)));

        self::assertCount(2, $lines);
        self::assertSame(['event' => 'begin', 'id' => 'p-1'], json_decode($lines[0], true));
        self::assertSame(['event' => 'end', 'id' => 'p-1'], json_decode($lines[1], true));
    }

    /**
     * Readable while the writer still holds the file open — the property the
     * live panel depends on, and the one a missing fflush silently removes.
     */
    #[Test]
    public function a_line_is_readable_before_the_writer_closes(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-live']);

        // No close, no reopen: read the file as the panel would, right now.
        self::assertStringContainsString('p-live', (string) file_get_contents($this->journalPath()));
    }

    /**
     * THE STATIC TRAP: the directory changes, the day does not.
     *
     * A handle cached on the day alone keeps writing into the previous
     * directory, so the second write lands in the first test's file and the
     * second file is never created.
     */
    #[Test]
    public function a_changed_directory_is_followed_even_on_the_same_day(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-first']);

        $second = sys_get_temp_dir() . '/semitexa-journal-second-' . uniqid('', true);
        mkdir($second, 0755, true);

        try {
            putenv('SEMITEXA_OBSERVATORY_DIR=' . $second);
            ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-second']);

            self::assertFileExists($this->journalPath($second), 'the new directory was never written to');
            self::assertStringContainsString('p-second', (string) file_get_contents($this->journalPath($second)));
            self::assertStringNotContainsString(
                'p-second',
                (string) file_get_contents($this->journalPath()),
                'the line went to the previous directory — the handle outlived its path',
            );
        } finally {
            putenv('SEMITEXA_OBSERVATORY_DIR=' . $this->dir);
            foreach (glob($second . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($second);
        }
    }

    /** A line over the cap is dropped rather than truncated into broken JSON. */
    #[Test]
    public function an_oversized_line_is_not_written(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'blob' => str_repeat('x', 2_000_000)]);

        self::assertFileDoesNotExist($this->journalPath());
    }

    /**
     * A HELD HANDLE CAN OUTLIVE ITS FILE. Deleting the journal — by hand, or by
     * anything that rotates it with a rename — used to leave every later write
     * going into an unlinked inode that no reader would ever see again.
     *
     * The check is throttled to once a second because fstat costs 2.07us
     * against 3.80us for the whole write, so the timestamp is reset here rather
     * than sleeping through the window.
     */
    #[Test]
    public function a_deleted_journal_is_reopened_rather_than_written_into_a_ghost(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-before']);
        self::assertFileExists($this->journalPath());

        unlink($this->journalPath());
        (new \ReflectionProperty(ObservatoryJournal::class, 'streamCheckedAt'))->setValue(null, 0);

        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-after']);

        self::assertFileExists($this->journalPath(), 'the write went into the deleted inode');
        self::assertStringContainsString('p-after', (string) file_get_contents($this->journalPath()));
    }

    /** And an intact file is NOT reopened — the check must not churn handles. */
    #[Test]
    public function an_intact_journal_keeps_its_handle(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-one']);
        $handle = (new \ReflectionProperty(ObservatoryJournal::class, 'stream'))->getValue();

        (new \ReflectionProperty(ObservatoryJournal::class, 'streamCheckedAt'))->setValue(null, 0);
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-two']);

        self::assertSame(
            $handle,
            (new \ReflectionProperty(ObservatoryJournal::class, 'stream'))->getValue(),
            'reopening a healthy file would throw away the whole point of keeping it open',
        );
    }

    /**
     * ROTATION BY RENAME leaves the old inode with nlink == 1, perfectly
     * healthy — so an existence check passes and every later record goes into
     * the archive while the live reader watches the original path.
     */
    #[Test]
    public function a_renamed_journal_is_reopened_at_the_original_path(): void
    {
        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-before']);
        rename($this->journalPath(), $this->journalPath() . '.1');
        // A REPLACEMENT AT THE ORIGINAL PATH, which is what rotation actually
        // leaves behind. Without it stat() simply fails and any check would
        // reopen; with it the path exists and only the inode differs, so this
        // is the case that separates identity from existence.
        touch($this->journalPath());
        (new \ReflectionProperty(ObservatoryJournal::class, 'streamCheckedAt'))->setValue(null, 0);

        ObservatoryJournal::write(['event' => 'begin', 'id' => 'p-after']);

        self::assertFileExists($this->journalPath(), 'the write followed the rename into the archive');
        self::assertStringContainsString('p-after', (string) file_get_contents($this->journalPath()));
        self::assertStringNotContainsString('p-after', (string) file_get_contents($this->journalPath() . '.1'));
    }
}
