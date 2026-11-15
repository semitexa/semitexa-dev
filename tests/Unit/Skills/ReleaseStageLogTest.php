<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A preflight stage's log is complete when the stage returns. It was written
 * through `> >(tee …)`, which bash runs on its own: the stage could return
 * while tee still held its last lines, and upgrade-notes-removed reads
 * bc-gate.log right after (review, semitexa-dev#267). A pipeline waits for tee;
 * the race did not reproduce on demand, so the source check below guards it, and
 * the runs check what the pipeline must keep: the log, the command's failure
 * under pipefail, the failure tail. The functions run as release-preflight.sh
 * defines them.
 */
final class ReleaseStageLogTest extends TestCase
{
    private const PREFLIGHT = __DIR__ . '/../../../resources/skills/release-readiness/scripts/release-preflight.sh';

    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/release-stage-log-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function the_log_holds_the_output_when_the_stage_returns(): void
    {
        // The last line comes from a child, after a pause: read back from the log as soon as the stage returns.
        [$code, $out] = $this->run_("run_stage gate bash -c 'echo first; (sleep 0.3; echo \"[ALLOWED] late\") & wait'\ncat \"\$RELEASE_STATE_DIR/gate.log\"");

        self::assertSame(0, $code, $out);
        self::assertSame(2, substr_count($out, '[ALLOWED] late'), 'once from tee on screen, once read back from the log right after the stage');
    }

    #[Test]
    public function a_failing_command_still_fails_the_stage_and_its_tail_is_complete(): void
    {
        [$code, $out] = $this->run_("run_stage gate bash -c 'echo before; echo \"why it failed\"; exit 3'");
        self::assertSame(1, $code, 'fail() ran');
        self::assertStringContainsString("FAILED: Stage 'gate' failed. Recent output:\nbefore\nwhy it failed", $out);

        [$code, $out] = $this->run_("run_soft_stage soft bash -c 'echo \"soft reason\"; exit 2'\nprintf 'collected=%s\\n' \"\${SOFT_FAILURES[0]}\"");
        self::assertSame(0, $code, 'a soft stage only records its failure');
        self::assertStringContainsString("collected=soft\nsoft reason", $out);
    }

    #[Test]
    public function no_stage_logs_through_process_substitution(): void
    {
        $source = (string) file_get_contents(self::PREFLIGHT);

        self::assertSame(2, substr_count($source, '"$@" 2>&1 | tee "$log_file"'), 'run_stage and run_soft_stage');
        // The race itself does not reproduce on demand: this is what keeps it from coming back.
        self::assertStringNotContainsString('"$@" > >(tee', $source);
    }

    /** @return array{int, string} */
    private function run_(string $calls): array
    {
        $source = (string) file_get_contents(self::PREFLIGHT);
        $functions = '';
        foreach (['run_stage', 'run_soft_stage'] as $name) {
            self::assertSame(1, preg_match('/^' . $name . '\(\) \{\n.*?^\}\n/ms', $source, $m), "release-preflight.sh defines {$name}()");
            $functions .= $m[0];
        }
        $script = "set -euo pipefail\nRELEASE_STATE_DIR=" . escapeshellarg($this->dir) . "\nSOFT_FAILURES=()\n"
            . "fail() { printf 'FAILED: %s\\n' \"\$*\"; exit 1; }\nwarn() { :; }\n"
            . $functions . $calls . "\n";
        file_put_contents($this->dir . '/harness.sh', $script);
        exec('bash ' . escapeshellarg($this->dir . '/harness.sh') . ' 2>&1', $lines, $code);

        return [$code, implode("\n", $lines)];
    }
}
