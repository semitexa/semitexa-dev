<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Runs actionlint on one changed GitHub workflow ({@see ActionlintTargets}).
 *
 * actionlint's own exit code is the verdict: 0 is no problem found, 1 is
 * problems found, and each is printed on a line of its own (`-oneline`). Any
 * other outcome (a bad option, a fatal error, a timeout, no tool at all) read
 * nothing, and a workflow nobody read is never a pass.
 *
 * Run from the workflow's repository, so a `.github/actionlint.yaml` there
 * applies as it does on any other machine.
 */
final class ActionlintCheck
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner,
        private readonly ActionlintTool $tool,
    ) {}

    public function check(VerificationTarget $target): VerificationResult
    {
        $rel = (string) $target->filePath;
        $abs = $this->projectRoot . '/' . $rel;
        if (!is_file($abs)) {
            return $this->unchecked($target, "{$rel} is not a file in this project");
        }

        $tool = $this->tool->obtain();
        if (!isset($tool['binary'])) {
            return $this->unchecked($target, "{$rel} was NOT read, actionlint could not be obtained: " . $tool['unavailable']);
        }

        // .github/workflows/<file> sits two directories below its repository.
        $r = $this->processRunner->run([$tool['binary'], '-no-color', '-oneline', $abs], dirname($abs, 3));
        if (isset($r['failure'])) {
            return $this->unchecked($target, "{$rel} was NOT read, actionlint did not finish ({$r['failure']}): " . SignalText::compress($r['output']));
        }

        if ($r['exit'] === 0) {
            return new VerificationResult($target, VerificationResult::STATUS_PASS, 0, 'actionlint ' . ActionlintTool::VERSION . " {$rel} → no problem found");
        }

        // A problem is `<path>:<line>:<col>: <message> [<rule>]`, as -oneline prints it.
        // Exit 1 with no such line listed nothing: that is not a verdict on the file.
        $diagnostics = [];
        $said = [];
        foreach (explode("\n", $r['output']) as $line) {
            if (preg_match('/^.+?:(\d+):(\d+): (.+?)(?: \[([a-z0-9-]+)\])?$/', trim($line), $m) !== 1) {
                continue;
            }
            $said[] = trim($line);
            $diagnostics[] = [
                'rule' => 'actionlint.' . ($m[4] ?? 'problem'),
                'file' => $rel,
                'line' => (int) $m[1],
                'column' => (int) $m[2],
                'message' => $m[3],
            ];
        }
        if ($r['exit'] === 1 && $diagnostics !== []) {
            return new VerificationResult(
                $target,
                VerificationResult::STATUS_FAIL,
                1,
                'actionlint ' . ActionlintTool::VERSION . " {$rel} → " . count($said) . ' problem' . (count($said) === 1 ? '' : 's') . ': ' . SignalText::compress(implode(' | ', $said)),
                diagnostics: $diagnostics,
            );
        }

        return $this->unchecked($target, "{$rel} was NOT read, actionlint gave no verdict (exit {$r['exit']}): " . SignalText::compress($r['output']));
    }

    private function unchecked(VerificationTarget $target, string $why): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, 'actionlint: not checked — ' . $why);
    }
}
