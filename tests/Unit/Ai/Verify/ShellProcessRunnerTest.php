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
}
