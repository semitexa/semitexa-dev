<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A failed preflight brings back the dev stack its stop stage took down.
 *
 * The clone and the workspace share host ports, so the stop stage stops the
 * dev stack and remembers it; finalize starts it again. A preflight that fails
 * has no finalize after it, and the workspace stayed without its app until
 * someone ran server:start (20.11). The preflight's exit handler now runs the
 * same restore on failure, and only on failure.
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
        [$code, $output] = $this->runPreflightExit('false', reportFails: true);

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

    /** @return array{int, string} */
    private function runPreflightExit(string $lastCommand, bool $reportFails = false): array
    {
        $source = (string) file_get_contents(self::PREFLIGHT);
        self::assertSame(1, preg_match('/^on_exit\(\) \{\n.*?^\}\n/ms', $source, $m), 'release-preflight.sh defines on_exit()');
        $onExit = $m[0] ?? '';
        $log = escapeshellarg($this->dir . '/calls.log');
        $script = "set -euo pipefail\nSCRIPT_DIR=" . escapeshellarg($this->dir) . "\nCURRENT_STAGE=sync-masters\n"
            . ($reportFails ? "write_failure_report() { return 1; }\n" : "write_failure_report() { echo \"report \$1\" >> {$log}; }\n")
            . "report_failure_reason() { echo reason; }\nwarn() { echo \"WARN \$*\"; }\n"
            . $onExit . "trap on_exit EXIT\n{$lastCommand}\n";
        file_put_contents($this->dir . '/harness.sh', $script);
        exec('bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }

    /** @return list<string> */
    private function calls(): array
    {
        $file = $this->dir . '/calls.log';

        return is_file($file) ? (file($file, FILE_IGNORE_NEW_LINES) ?: []) : [];
    }
}
