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

        if ($r['exit'] === 0) {
            $lines = array_values(array_filter(explode("\n", trim($r['output']))));

            return new VerificationResult($target, VerificationResult::STATUS_PASS, 0, "bc_gate:{$package} → " . ($lines === [] ? 'no break on the @api surface' : end($lines)));
        }

        return new VerificationResult(
            $target,
            VerificationResult::STATUS_FAIL,
            $r['exit'],
            "bc_gate:{$package} → " . SignalText::compress($r['output'])
                . ' — keep the @api signature, or mark it @deprecated and remove it 90 days later; the release refuses the same break',
        );
    }

    private function unchecked(VerificationTarget $target, string $why): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, 'bc_gate: not checked — ' . $why);
    }
}
