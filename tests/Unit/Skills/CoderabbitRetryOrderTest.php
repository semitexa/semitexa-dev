<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Skills;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Which skipped review the retry timer wakes. Ranked by the trigger time of
 * the head alone, every PR whose head had never been triggered tied at 0 and
 * the tie went to the repository name: a PR pushed to again and again kept
 * winning, and semitexa-workflow#26 waited a whole evening behind docs,
 * testing and ultimate (2026-10-28).
 */
final class CoderabbitRetryOrderTest extends TestCase
{
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    #[Test]
    public function the_pr_that_has_waited_longest_is_woken_first(): void
    {
        // docs was skipped at 2000, workflow at 1000; neither head was ever triggered.
        $picked = $this->pick(['semitexa-docs 109 2000', 'semitexa-workflow 26 1000']);

        self::assertSame('would trigger semitexa/semitexa-workflow#26 at wf26wf2 (2 waiting)', $picked);
    }

    #[Test]
    public function a_trigger_counts_as_attention_so_a_triggered_pr_waits_its_turn(): void
    {
        // docs was skipped first (400) but triggered at 1500; workflow, skipped at 1000, was not.
        $picked = $this->pick(['semitexa-docs 109 400', 'semitexa-workflow 26 1000'], ['semitexa-docs 109' => 1500]);

        self::assertSame('would trigger semitexa/semitexa-workflow#26 at wf26wf2 (2 waiting)', $picked);
    }

    #[Test]
    public function a_release_blocker_still_goes_first(): void
    {
        $picked = $this->pick(['semitexa-docs 109 2000 release-blocker', 'semitexa-workflow 26 1000']);

        self::assertSame('would trigger semitexa/semitexa-docs#109 at do109d0 (2 waiting, release blocker)', $picked);
    }

    /**
     * Runs the script with --dry-run against a fake gh that answers as the real
     * one does after --jq. Each PR: "<repo> <number> <skipped-at epoch> [label]".
     *
     * @param list<string> $prs
     * @param array<string, int> $triggered "<repo> <number>" => when its current head was last triggered
     */
    private function pick(array $prs, array $triggered = []): string
    {
        $this->dir = sys_get_temp_dir() . '/coderabbit-retry-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0o755, true);
        mkdir($this->dir . '/state');
        $search = '';
        $cases = '';
        foreach ($prs as $pr) {
            [$repo, $number, $skippedAt] = explode(' ', $pr);
            $label = explode(' ', $pr)[3] ?? null;
            $sha = substr(str_replace(['wo26', 'do109'], ['wf26wf2', 'do109d0'], substr($repo, 9, 2) . $number) . str_repeat('0', 40), 0, 40);
            $search .= "semitexa/{$repo} {$number}\n";
            $cases .= "  *'repos/semitexa/{$repo}/pulls/{$number}'*) echo '{$sha} " . ($label === null ? '[]' : "[\"{$label}\"]") . "' ;;\n";
            $cases .= "  *'repos/semitexa/{$repo}/commits/{$sha}/status'*) echo '' ;;\n";
            $cases .= "  *'repos/semitexa/{$repo}/commits/{$sha}/check-runs'*) echo '{$skippedAt} Review rate limited' ;;\n";
            if (isset($triggered["{$repo} {$number}"])) {
                file_put_contents($this->dir . "/state/semitexa_{$repo}-{$number}-{$sha}", $triggered["{$repo} {$number}"] . "\n");
            }
        }
        file_put_contents($this->dir . '/bin/gh', "#!/bin/bash\nargs=\"\$*\"\ncase \"\$args\" in\n  'auth status') exit 0 ;;\n  search*) printf '%s' " . escapeshellarg($search) . " ;;\n{$cases}  *) echo \"unexpected gh \$args\" >&2; exit 1 ;;\nesac\n");
        chmod($this->dir . '/bin/gh', 0o755);

        $script = dirname(__DIR__, 3) . '/resources/skills/codereview/scripts/coderabbit-retry.sh';
        exec(sprintf(
            'env -i PATH=%s:/usr/bin:/bin HOME=%s CODERABBIT_RETRY_STATE_DIR=%s bash %s --dry-run 2>&1',
            escapeshellarg($this->dir . '/bin'),
            escapeshellarg($this->dir),
            escapeshellarg($this->dir . '/state'),
            escapeshellarg($script),
        ), $out, $status);
        self::assertSame(0, $status, implode("\n", $out));

        return (string) preg_replace('/^\S+ dry-run: /', '', (string) end($out));
    }
}
