<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * The syntax check for a changed client script (.js / .mjs), run by
 * {@see VerificationExecutor} for a syntax target whose file is JavaScript.
 *
 * It exists because nothing read client JavaScript a package ships: the only
 * check its kind selected was lint:mechanisms, which scans src/modules alone.
 */
final readonly class JavaScriptSyntaxCheck
{
    public function __construct(
        private ProcessRunner $processRunner,
        private string $projectRoot,
        // Null = look `node` up on PATH. A path that is not executable counts
        // as absent, which is how a test pins the no-node verdict.
        private ?string $nodeBinary = null,
    ) {}

    /**
     * `node --check`: parses the file and runs none of it.
     *
     * NOT a bare `node --check <file.js>`, and that was measured: on node 24 a
     * .js file carrying import/export goes through module-type detection, and
     * a syntax error found there exits 0 with no output. A broken ui-core.js
     * passed that way. So the two grammars a browser can load a .js file under
     * are checked explicitly: first as a classic script with detection off,
     * then — only if that does not parse — as an ES module, from an .mjs copy,
     * since `--input-type` applies to stdin and -e alone. Either parse is a
     * pass: which one the page uses is decided by its <script> tag, not here.
     * An .mjs is a module by definition and is checked as one directly.
     *
     * No node means the file was NOT read, and the verdict says so: a required
     * target that could not run is incomplete, never a pass. A gate that cannot
     * check must not pass.
     */
    public function check(VerificationTarget $target, string $rel, string $abs): VerificationResult
    {
        $node = $this->resolveNodeBinary();
        if ($node === null) {
            return self::notChecked($target, "node is not available in this runtime, so the JavaScript syntax of {$rel} was NOT checked");
        }

        $script = null;
        if (!str_ends_with($rel, '.mjs')) {
            $script = $this->processRunner->run([$node, '--no-experimental-detect-module', '--check', $abs], $this->projectRoot);
            // A node older than the flag (< 20.10) has no detection to switch off.
            if (!isset($script['failure']) && $script['exit'] === 9 && str_contains($script['output'], 'bad option')) {
                $script = $this->processRunner->run([$node, '--check', $abs], $this->projectRoot);
            }
            if (isset($script['failure'])) {
                return $this->nodeCouldNotRun($target, $rel, $script);
            }
            if ($script['exit'] === 0) {
                return new VerificationResult($target, VerificationResult::STATUS_PASS, 0, "node --check {$rel} → parses as a classic script");
            }
        }

        $copy = null;
        $modulePath = $abs;
        if ($script !== null) {
            $copy = $this->moduleCopy($abs);
            if ($copy === null) {
                return self::notChecked($target, "could not stage an .mjs copy of {$rel}, so it was NOT checked as an ES module");
            }
            $modulePath = $copy;
        }
        try {
            $module = $this->processRunner->run([$node, '--check', $modulePath], $this->projectRoot);
        } finally {
            if ($copy !== null) {
                @unlink($copy);
            }
        }
        if (isset($module['failure'])) {
            return $this->nodeCouldNotRun($target, $rel, $module);
        }
        if ($module['exit'] === 0) {
            return new VerificationResult($target, VerificationResult::STATUS_PASS, 0, "node --check {$rel} → parses as an ES module");
        }

        // Neither grammar accepts it. Report the error from the grammar the
        // file is evidently written in: import/export means module.
        $asModule = $script === null || self::looksLikeModule($abs);

        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_FAIL,
            exitCode: $asModule ? $module['exit'] : $script['exit'],
            signal:   $asModule
                ? self::nodeSyntaxSignal($module['output'], $modulePath, $rel)
                : self::nodeSyntaxSignal($script['output'], $abs, $rel),
        );
    }

    /** @param array{exit: int, output: string, failure?: string} $r */
    private function nodeCouldNotRun(VerificationTarget $target, string $rel, array $r): VerificationResult
    {
        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_INCOMPLETE,
            exitCode: $r['exit'],
            signal:   "node --check could not run on {$rel}: " . SignalText::lastLine($r['output']),
        );
    }

    private function moduleCopy(string $abs): ?string
    {
        $base = tempnam(sys_get_temp_dir(), 'semitexa-jscheck-');
        if ($base === false) {
            return null;
        }
        @unlink($base);
        $copy = $base . '.mjs';

        return @copy($abs, $copy) ? $copy : null;
    }

    private static function looksLikeModule(string $abs): bool
    {
        $source = @file_get_contents($abs);

        return is_string($source) && preg_match('/^[ \t]*(?:import[\s{*"\']|export\s)/m', $source) === 1;
    }

    /**
     * node prints `<file>:<line>`, the offending source line, a caret, a blank
     * line, `SyntaxError: …`, a stack, and finally its own version — so the
     * last line, which is what `php -l` is read by, says nothing at all here.
     */
    private static function nodeSyntaxSignal(string $output, string $abs, string $rel): string
    {
        $location = null;
        $error = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($location === null && str_starts_with($line, $abs . ':')) {
                $location = $rel . substr($line, strlen($abs));
            }
            if ($error === null && preg_match('/^[A-Za-z]*Error: /', $line) === 1) {
                $error = $line;
            }
        }

        if ($error === null) {
            return "node --check {$rel} failed: " . SignalText::lastLine($output);
        }

        return ($location ?? $rel) . ' ' . $error;
    }

    public function resolveNodeBinary(): ?string
    {
        if ($this->nodeBinary !== null) {
            return is_file($this->nodeBinary) && is_executable($this->nodeBinary) ? $this->nodeBinary : null;
        }

        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin') as $dir) {
            if ($dir === '') {
                continue;
            }
            $candidate = rtrim($dir, '/') . '/node';
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** A required check that could not read the file: incomplete, never a pass. */
    private static function notChecked(VerificationTarget $target, string $reason): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, $reason, required: true);
    }
}
