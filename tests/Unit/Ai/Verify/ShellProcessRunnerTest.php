<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Ai\Verify;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Dev\Application\Service\Ai\Verify\ShellProcessRunner;

final class ShellProcessRunnerTest extends TestCase
{
    /**
     * A child that fills stderr before it closes stdout must not hang the
     * runner. Reading stdout to EOF first and stderr second deadlocks as soon
     * as stderr exceeds the pipe buffer — 64 KB normally, one 4 KB page when
     * the kernel's per-user pipe quota is exhausted, which a Swoole box with
     * hundreds of worker pipes reaches. skills-sync.sh --check hung ai:verify
     * for 13 minutes exactly this way (BusyBox find printing its usage).
     */
    #[Test]
    public function a_child_that_floods_stderr_still_completes(): void
    {
        $script = 'fwrite(STDERR, str_repeat("e", 300000)); echo "done"; exit(3);';

        $started = microtime(true);
        $r = (new ShellProcessRunner())->run([PHP_BINARY, '-r', $script], sys_get_temp_dir());

        self::assertLessThan(10, microtime(true) - $started, 'the runner must never wait on a full pipe');
        self::assertSame(3, $r['exit']);
        self::assertStringContainsString('done', $r['output']);
        self::assertStringContainsString('eee', $r['output'], 'stderr is part of the captured output');
    }

    #[Test]
    public function a_missing_binary_is_reported_not_thrown(): void
    {
        $r = (new ShellProcessRunner())->run(['/definitely/not/here'], sys_get_temp_dir());

        self::assertNotSame(0, $r['exit']);
        self::assertNotSame('', $r['output']);
    }

    public function testSilentChildHasADeadline(): void
    {
        $started = microtime(true);
        $result = (new ShellProcessRunner(timeoutSeconds: 0.2))->run([PHP_BINARY, '-r', 'sleep(20);'], sys_get_temp_dir());
        self::assertLessThan(3, microtime(true) - $started);
        self::assertSame(124, $result['exit']);
        self::assertSame('timeout', $result['failure']);
    }

    public function testOutputFloodHasABoundedBufferAndNonzeroExit(): void
    {
        $started = microtime(true);
        $result = (new ShellProcessRunner(timeoutSeconds: 2, maxOutputBytes: 4096))->run([PHP_BINARY, '-r', 'while (true) { echo str_repeat("x", 8192); }'], sys_get_temp_dir());
        self::assertLessThan(3, microtime(true) - $started);
        self::assertSame(125, $result['exit']);
        self::assertSame('output_limit', $result['failure']);
        self::assertLessThanOrEqual(4096, strlen($result['output']));
    }

    public function testDescendantHoldingStdoutCannotOutliveTheDeadline(): void
    {
        $marker = sys_get_temp_dir() . '/semitexa-child-marker-' . bin2hex(random_bytes(8));
        $child = 'usleep(700000); file_put_contents(' . var_export($marker, true) . ', "escaped");';
        // The direct shell exits immediately. Its child still holds our pipe.
        $result = (new ShellProcessRunner(timeoutSeconds: 0.2))->run(['sh', '-c', '"$@" &', 'sh', PHP_BINARY, '-r', $child], sys_get_temp_dir());
        try {
            self::assertSame(124, $result['exit']);
            usleep(800000);
            self::assertFileDoesNotExist($marker, 'the descendant must have been terminated with its process group');
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
        }
    }

    public function testArgumentsAreNotInterpretedByAShell(): void
    {
        $arg = 'spaces; $(echo unexpected) "quotes"';
        $result = (new ShellProcessRunner())->run([PHP_BINARY, '-r', 'echo $argv[1];', $arg], sys_get_temp_dir());
        self::assertSame(0, $result['exit']);
        self::assertSame($arg, $result['output']);
    }
}
