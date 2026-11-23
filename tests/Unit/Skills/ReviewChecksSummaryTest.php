<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * run-review-checks.sh writes the checks a PR body lists
 * (tk-review-checks-records-a-failed-check-as-passed). On 2026-11-20 it wrote
 * "- [x] composer phpstan" for semitexa-ultimate#172 although the check had
 * exited 1 ("service app is not running"): the line was written before the
 * command ran.
 */
final class ReviewChecksSummaryTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/review-checks-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        mkdir($this->dir . '/repo');
        file_put_contents($this->dir . '/repo/composer.json', json_encode(['scripts' => ['phpstan' => 'phpstan', 'test' => 'phpunit']], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    #[Test]
    public function a_failed_check_is_recorded_as_failed_and_stops_the_run(): void
    {
        [$status, $out, $summary] = $this->check(['phpstan' => "echo 'service \"app\" is not running' >&2; exit 1"]);

        self::assertSame(1, $status, $out);
        self::assertSame("- [ ] composer phpstan: failed (exit 1)\n", $summary, 'no [x] for it, and nothing after it ran');
        self::assertStringContainsString('Check failed: composer phpstan (exit 1)', $out);
        self::assertStringNotContainsString('RAN test', $out, 'the run stopped at the failure');
    }

    #[Test]
    public function a_failed_check_keeps_its_own_exit_code(): void
    {
        [$status, , $summary] = $this->check(['test' => 'exit 3']);

        self::assertSame(3, $status);
        self::assertSame("- [x] composer phpstan\n- [ ] composer test: failed (exit 3)\n", $summary);
    }

    #[Test]
    public function checks_that_pass_are_recorded_after_they_ran(): void
    {
        [$status, $out, $summary] = $this->check([]);

        self::assertSame(0, $status, $out);
        self::assertSame("- [x] composer phpstan\n- [x] composer test\n", $summary);
        self::assertSame("RAN phpstan\n- [x] composer phpstan\nRAN test\n- [x] composer test", $out, 'each line is written after its check ran');
    }

    /**
     * Runs the script with a `composer` stand-in that runs each named script's
     * shell body, and passes (printing "RAN <name>") for the others. The
     * script asks python3 which scripts composer.json declares; the image the
     * tests run in has no python3, so a stand-in answers: phpstan and test.
     *
     * @param array<string, string> $bodies composer script name => shell body
     * @return array{int, string, string} exit code, output, summary file
     */
    private function check(array $bodies): array
    {
        $cases = '';
        foreach ($bodies as $name => $body) {
            $cases .= "  {$name}) {$body} ;;\n";
        }
        file_put_contents($this->dir . '/bin/composer', "#!/bin/bash\ncase \"\$1\" in\n{$cases}  *) echo \"RAN \$1\" ;;\nesac\n");
        // `python3 - <file> <name>`: composer.json declares phpstan and test, package.json is absent.
        file_put_contents($this->dir . '/bin/python3', "#!/bin/bash\ncase \"\$2:\$3\" in\n  */composer.json:phpstan|*/composer.json:test) exit 0 ;;\n  *) exit 1 ;;\nesac\n");
        self::assertTrue(chmod($this->dir . '/bin/composer', 0o755) && chmod($this->dir . '/bin/python3', 0o755), 'the stand-ins are executable');

        $script = dirname(__DIR__, 3) . '/resources/skills/review-prep/scripts/run-review-checks.sh';
        $summary = $this->dir . '/checks.txt';
        exec(sprintf(
            'env -i HOME=%s PATH=%s:/usr/bin:/bin bash %s %s --summary-file %s 2>&1',
            escapeshellarg($this->dir),
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg($script),
            escapeshellarg($this->dir . '/repo'),
            escapeshellarg($summary),
        ), $out, $status);

        return [$status, implode("\n", $out), (string) file_get_contents($summary)];
    }
}
