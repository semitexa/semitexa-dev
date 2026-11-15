<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Runs the release's BC gate for one package against its working tree
 * ({@see BcGateTargets}).
 *
 * The same script the cut runs, so the edit and the release judge a break
 * alike: roave between the package's last tag and `git stash create` (a commit
 * of the tracked working tree that moves no ref; a new untracked file adds
 * API, it cannot break any), kept only where it touches the @api surface,
 * refused unless deprecated 90 days before. roave lives in var/tools/roave of
 * the workspace, as in the release clone, and is installed the first time.
 */
final class BcGateCheck
{
    public const TOOL_DIR = 'var/tools/roave';

    private const TOOL = self::TOOL_DIR . '/vendor/bin/roave-backward-compatibility-check';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner,
        private readonly string $phpBinary = PHP_BINARY,
    ) {}

    public function check(VerificationTarget $target): VerificationResult
    {
        $packageDir = (string) $target->filePath;
        $package = basename($packageDir);
        $script = $this->projectRoot . '/' . BcGateTargets::GATE_SCRIPT;
        if (!is_file($script)) {
            return new VerificationResult($target, VerificationResult::STATUS_SKIPPED, 0, 'bc_gate: no release-bc-gate.php in this project; the contract is checked in the framework workspace', required: false);
        }

        $tool = $this->projectRoot . '/' . self::TOOL;
        if (!is_file($tool)) {
            @mkdir($this->projectRoot . '/' . self::TOOL_DIR, 0775, true);
            $install = $this->processRunner->run(['composer', 'require', '--no-interaction', '--no-progress', '--working-dir=' . $this->projectRoot . '/' . self::TOOL_DIR, 'roave/backward-compatibility-check:^8.27'], $this->projectRoot);
            if ($install['exit'] !== 0 || !is_file($tool)) {
                // A gate that cannot run is not a pass.
                return $this->unchecked($target, 'composer could not install roave into ' . self::TOOL_DIR . ': ' . SignalText::compress($install['output']));
            }
        }

        $stash = $this->processRunner->run(['git', '-C', $this->projectRoot . '/' . $packageDir, 'stash', 'create'], $this->projectRoot);
        if ($stash['exit'] !== 0) {
            return $this->unchecked($target, 'git stash create failed in ' . $packageDir . ': ' . SignalText::compress($stash['output']));
        }
        // Empty: nothing tracked is modified, the tree is HEAD.
        $to = trim($stash['output']) !== '' ? trim($stash['output']) : 'HEAD';

        $r = $this->processRunner->run([
            'env',
            'RELEASE_ROOT=' . $this->projectRoot,
            'SEMITEXA_BC_TO=' . $to,
            'SEMITEXA_AUTOLOAD=' . $this->projectRoot . '/vendor/autoload.php',
            'SEMITEXA_BC_CHECK=' . escapeshellarg($this->phpBinary) . ' ' . escapeshellarg($tool),
            $this->phpBinary,
            $script,
            '--package=' . $package,
        ], $this->projectRoot);

        // Timed out or killed: the gate said nothing about the API (review, dev#265).
        if (isset($r['failure'])) {
            return $this->unchecked($target, 'the gate did not finish (' . $r['failure'] . '): ' . SignalText::compress($r['output']));
        }
        $lines = array_values(array_filter(array_map('trim', explode("\n", $r['output']))));

        // Only a [BREAK] line is a verdict against the API; the gate exits 1 for
        // it and for what it could not check ([UNCHECKED]) alike (review, dev#265).
        if ($r['exit'] !== 0 && preg_grep('/^\[BREAK\] /', $lines) !== []) {
            return new VerificationResult(
                $target,
                VerificationResult::STATUS_FAIL,
                $r['exit'],
                "bc_gate:{$package} → " . SignalText::compress($r['output'])
                    . ' — keep the @api signature, or mark it @deprecated and remove it 90 days later; the release refuses the same break.'
                    . ' A file not yet tracked is not in the comparison: a class moved into one reads as removed until `git add -N` it',
            );
        }

        // A pass is the gate saying it judged: its own last line, nothing else.
        $verdict = end($lines);
        if ($r['exit'] === 0 && is_string($verdict)
            && ($verdict === 'No break on the @api surface.' || preg_match('/^\d+ break\(s\), each deprecated long enough\.$/', $verdict) === 1)) {
            // An exempted tag was not judged: say so beside the pass.
            $exempt = array_values(preg_grep('/^\[EXEMPT\] /', $lines) ?: []);

            return new VerificationResult($target, VerificationResult::STATUS_PASS, 0, "bc_gate:{$package} → {$verdict}" . ($exempt === [] ? '' : ' ' . SignalText::compress(implode('; ', $exempt))));
        }

        return $this->unchecked($target, 'the gate gave no verdict (exit ' . $r['exit'] . '): ' . SignalText::compress($r['output']));
    }

    private function unchecked(VerificationTarget $target, string $why): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, 'bc_gate: not checked — ' . $why);
    }
}
