<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A failed preflight brings back the dev stack its stop stage took down.
 *
 * The clone and the workspace share host ports, so the stop stage stops the
 * dev stack and remembers it; finalize starts it again. A preflight that fails
 * has no finalize after it, and the workspace stayed without its app until
 * someone ran server:start (20.11). The preflight's exit handler now runs the
 * same restore on failure, and only on failure: a failure report that exits,
 * and a run stopped by SIGINT or SIGTERM, count as failures too.
 */
final class FailedPreflightRestoresDevStackTest extends TestCase
{
    private const PREFLIGHT = __DIR__ . '/../../../resources/skills/release-readiness/scripts/release-preflight.sh';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/failed-preflight-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/release-restore-dev.sh', "#!/usr/bin/env bash\necho restored >> " . escapeshellarg($this->dir . '/calls.log') . "\n");
        chmod($this->dir . '/release-restore-dev.sh', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function a_failed_run_starts_the_dev_stack_again(): void
    {
        [$code, $output] = $this->runPreflightExit('false');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('RELEASE READINESS: FAIL', $output);
        self::assertSame(['report sync-masters', 'restored'], $this->calls());
    }

    /**
     * Under set -e a report that cannot be written (a full disk) ended the
     * handler before the restore (review, dev#296). The restore still runs, and
     * the run keeps its own status.
     */
    #[Test]
    public function a_report_that_cannot_be_written_does_not_stop_the_restore(): void
    {
        [$code, $output] = $this->runPreflightExit('false', report: 'return 1');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('WARN Could not write the failure report', $output);
        self::assertSame(['restored'], $this->calls());
    }

    /**
     * The report loads the release session, and with no session.env that calls
     * fail, which exits. An exit inside the handler ended it before the restore,
     * and the `|| warn` after the report never saw it (review, dev#296).
     */
    #[Test]
    public function a_report_that_exits_does_not_stop_the_restore(): void
    {
        [$code, $output] = $this->runPreflightExit('false', report: 'exit 1');

        self::assertSame(1, $code, $output);
        self::assertStringContainsString('WARN Could not write the failure report', $output);
        self::assertSame(['restored'], $this->calls());
    }

    #[Test]
    public function a_passing_run_leaves_the_restore_to_finalize(): void
    {
        [$code, $output] = $this->runPreflightExit('true');

        self::assertSame(0, $code, $output);
        self::assertSame([], $this->calls(), 'finalize restores after a pass; the clone is still needed until then');
    }

    /**
     * A signal sent to the preflight while a stage runs in the foreground.
     * Without the traps, SIGTERM ran the handler with status 0 (no restore) and
     * SIGINT let the run go on to its next command and pass.
     */
    #[Test]
    #[DataProvider('signals')]
    public function an_interrupted_run_starts_the_dev_stack_again(string $signal, int $status): void
    {
        [$state, $output] = $this->interrupt($signal, again: false);

        self::assertFalse($state['signaled'], "the run ends through its handler, not killed by SIG{$signal}\n" . $output);
        self::assertSame($status, $state['exitcode'], $output);
        self::assertSame(['report sync-masters', 'restored'], $this->calls(), 'the next stage never runs, and the dev stack is restored');
    }

    /**
     * A double Ctrl-C, or a supervisor that repeats TERM: the second signal lands
     * while the handler writes the report. It turned into an exit there and
     * skipped the restore (review of this fix).
     */
    #[Test]
    #[DataProvider('signals')]
    public function a_second_signal_during_the_handler_does_not_stop_the_restore(string $signal, int $status): void
    {
        [$state, $output] = $this->interrupt($signal, again: true);

        self::assertFalse($state['signaled'], $output);
        self::assertSame($status, $state['exitcode'], $output);
        self::assertSame(['report sync-masters', 'restored'], $this->calls());
    }

    /** @return iterable<string, array{string, int}> */
    public static function signals(): iterable
    {
        yield 'SIGTERM' => ['TERM', 143];
        yield 'SIGINT' => ['INT', 130];
    }

    /**
     * Runs the harness through a stage that sleeps, sends $signal while it runs
     * and, with $again, once more while the handler writes the report.
     *
     * @return array{array{running: bool, signaled: bool, exitcode: int}, string}
     */
    private function interrupt(string $signal, bool $again): array
    {
        $stage = $this->dir . '/stage-started';
        $report = $this->dir . '/report-started';
        $log = escapeshellarg($this->dir . '/calls.log');
        $script = $this->harness(
            'touch ' . escapeshellarg($stage) . "\nsleep 1\necho next-stage >> {$log}",
            report: 'touch ' . escapeshellarg($report) . "; sleep 1; echo \"report \$1\" >> {$log}",
        );

        $process = proc_open(['bash', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $pid = proc_get_status($process)['pid'];
        $this->waitFor($stage);
        exec('kill -' . $signal . ' ' . $pid);
        if ($again) {
            $this->waitFor($report);
            exec('kill -' . $signal . ' ' . $pid);
        }

        $deadline = microtime(true) + 10;
        do {
            $state = proc_get_status($process);
            usleep(10_000);
        } while ($state['running'] && microtime(true) < $deadline);
        if ($state['running']) {
            proc_terminate($process, 9);
        }
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        proc_close($process);
        self::assertFalse($state['running'], "the run ends within 10 s\n" . $output);

        return [$state, $output];
    }

    private function waitFor(string $file): void
    {
        for ($i = 0; $i < 500 && !is_file($file); $i++) {
            usleep(10_000);
        }
        self::assertFileExists($file);
    }

    /** @return array{int, string} */
    private function runPreflightExit(string $lastCommand, string $report = ''): array
    {
        $log = escapeshellarg($this->dir . '/calls.log');
        $script = $this->harness($lastCommand, $report !== '' ? $report : "echo \"report \$1\" >> {$log}");
        exec('bash ' . escapeshellarg($script) . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /**
     * The preflight's own on_exit() and trap lines, with the report, the reason
     * and warn stubbed, then the given commands.
     */
    private function harness(string $commands, string $report): string
    {
        $source = (string) file_get_contents(self::PREFLIGHT);
        self::assertSame(1, preg_match('/^on_exit\(\) \{\n.*?^\}\n/ms', $source, $m), 'release-preflight.sh defines on_exit()');
        self::assertGreaterThan(0, preg_match_all('/^trap [^\n]+$/m', $source, $traps), 'release-preflight.sh sets its traps');
        $script = "set -euo pipefail\nSCRIPT_DIR=" . escapeshellarg($this->dir) . "\nCURRENT_STAGE=sync-masters\n"
            . "write_failure_report() { {$report}; }\n"
            . "report_failure_reason() { echo reason; }\nwarn() { echo \"WARN \$*\"; }\n"
            . $m[0] . implode("\n", $traps[0]) . "\n{$commands}\n";
        file_put_contents($this->dir . '/harness.sh', $script);

        return $this->dir . '/harness.sh';
    }

    /** @return list<string> */
    private function calls(): array
    {
        $file = $this->dir . '/calls.log';

        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }
}
