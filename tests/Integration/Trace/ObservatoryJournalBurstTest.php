<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Integration\Trace;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REGRESSION, the same trap as core's AsyncJsonLoggerBurstTest. Under
 * SWOOLE_HOOK_FILE a flock that has to wait is slow, about 23 ms per waiting
 * coroutine, and every journal line is written on the request's own coroutine.
 * Measured 2026-10-19 on dev, 200 coroutines at once:
 *
 *  - pulse(): file_put_contents(FILE_APPEND|LOCK_EX) opens the file per line,
 *    so each line takes its own lock on its own handle: 3.46 s.
 *  - write() on a cold worker: every coroutine saw no handle, opened one of its
 *    own across the yielding fopen, and they contended exactly as above: 3.06 s.
 *    On a warm handle the same burst took 0.004 s, because flock on the one
 *    handle a process already holds does not wait.
 *
 * In a child process: hooks are process-wide, and an active proc_open hook
 * outside a coroutine is an uncatchable fatal for every later test.
 */
final class ObservatoryJournalBurstTest extends TestCase
{
    private const BURST = <<<'PHP'
        <?php
        require $argv[1];
        [, , $method, $lines] = $argv;
        Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        $startedAt = microtime(true);
        Swoole\Coroutine\run(static function () use ($method, $lines): void {
            for ($i = 0; $i < (int) $lines; $i++) {
                Swoole\Coroutine::create(static function () use ($method, $i): void {
                    Semitexa\Dev\Application\Service\Trace\ObservatoryJournal::$method(['event' => 'burst', 'i' => $i]);
                });
            }
        });
        echo microtime(true) - $startedAt;
        PHP;

    /** @return iterable<string, array{string, string}> */
    public static function writers(): iterable
    {
        yield 'journal, cold worker' => ['write', 'journal-'];
        yield 'pulse' => ['pulse', 'pulses-'];
    }

    #[Test]
    #[DataProvider('writers')]
    public function a_burst_of_coroutines_is_written_without_lock_contention(string $method, string $prefix): void
    {
        $dir = sys_get_temp_dir() . '/semitexa-journal-burst-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o755, true);
        $script = $dir . '/burst.php';
        file_put_contents($script, self::BURST);
        $autoload = dirname((string) (new \ReflectionClass(ClassLoader::class))->getFileName(), 2) . '/autoload.php';

        try {
            $process = proc_open(
                [PHP_BINARY, $script, $autoload, $method, '200'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $dir,
                ['SEMITEXA_OBSERVATORY_DIR' => $dir] + getenv(),
            );
            self::assertIsResource($process);
            $took = stream_get_contents($pipes[1]);
            $errors = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), 'the burst process failed: ' . $errors);

            $file = $dir . '/' . $prefix . date('Ymd') . '.ndjson';
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($lines, 'nothing was written to ' . basename($file));
            $written = array_map(
                static fn (string $line): int => json_decode($line, true, 512, JSON_THROW_ON_ERROR)['i'],
                $lines,
            );
            sort($written);
            // Every index once: a count alone passes with one line lost and another doubled.
            self::assertSame(range(0, 199), $written, 'a line of the burst was lost');
            self::assertIsNumeric($took, 'the burst process did not report its time: ' . $took);
            self::assertLessThan(1.0, (float) $took, 'the burst was written one contended lock at a time');
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
