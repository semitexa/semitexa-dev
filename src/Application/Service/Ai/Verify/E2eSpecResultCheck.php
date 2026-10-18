<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Reads the outcome of a changed spec that `bin/semitexa ai:verify` ran on the
 * host just before this verification (see {@see E2eSpecTargets}).
 *
 * The host files each outcome under this run's id — SEMITEXA_VERIFY_E2E_RUN,
 * handed into the container — as var/run/verify-e2e/<run>/<key>.exit and .log,
 * where the key hashes the path and the content: a spec edited after it ran is
 * not judged by the old result, and two specs with the same body do not share
 * one. Anything without a result is incomplete and says why: a gate that
 * cannot check must not pass.
 */
final readonly class E2eSpecResultCheck
{
    public const RUN_ENV = 'SEMITEXA_VERIFY_E2E_RUN';
    public const RESULTS_DIR = 'var/run/verify-e2e';

    public function __construct(
        private string $projectRoot,
        private ?string $runId,
    ) {}

    public static function fromEnvironment(string $projectRoot): self
    {
        $run = getenv(self::RUN_ENV);

        return new self($projectRoot, is_string($run) && $run !== '' ? $run : null);
    }

    public function check(VerificationTarget $target): VerificationResult
    {
        $rel = (string) $target->filePath;
        $abs = $this->projectRoot . '/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            return new VerificationResult($target, VerificationResult::STATUS_SKIPPED, 0, "file no longer exists: {$rel}");
        }
        if ($this->runId === null || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}\z/', $this->runId) !== 1) {
            return self::notRun($target, "{$rel} was NOT run: changed specs run on the host, and this verification was not started by bin/semitexa ai:verify");
        }

        $dir = $this->projectRoot . '/' . self::RESULTS_DIR . '/' . $this->runId;
        $hash = self::key($rel, (string) hash_file('sha256', $abs));
        $exit = @file_get_contents("{$dir}/{$hash}.exit");
        if (!is_string($exit) || preg_match('/\A\s*(\d+)\s*\z/', $exit, $m) !== 1) {
            $host = @file_get_contents("{$dir}/host.txt");
            $why = is_string($host) && trim($host) !== '' ? trim($host) : 'the host filed no result for its current content (edited after the run, or the runner did not start)';

            return self::notRun($target, "{$rel} was NOT run: {$why}");
        }

        $code = (int) $m[1];
        $log = (string) @file_get_contents("{$dir}/{$hash}.log");
        $summary = self::summary($log);
        // No counts means Playwright never reported: the runner image, compose
        // or the config failed, and that says nothing about the spec.
        if (preg_match('/\d+ (?:passed|failed|flaky)/', $summary) !== 1) {
            return self::notRun($target, "{$rel} was NOT run: the e2e-runner exited {$code} without a test report — " . SignalText::lastLine($log));
        }

        return new VerificationResult(
            target:   $target,
            status:   $code === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode: $code,
            // A changed handler is only live after a restart, which verify advises and never does.
            signal:   "playwright {$rel}, against the app as it was running → {$summary}",
        );
    }

    /** The result file name the host writes: sha256 of the path, a dash, sha256 of the content. */
    public static function key(string $rel, string $contentHash): string
    {
        return hash('sha256', $rel) . '-' . $contentHash;
    }

    /** Playwright's closing counts ("2 passed", "1 failed"), and the first error when there is one. */
    public static function summary(string $log): string
    {
        $text = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $log);
        $counts = [];
        $error = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/\A\d+ (?:passed|failed|flaky|skipped|did not run|interrupted)\b/', $line) === 1) {
                $counts[] = $line;
            } elseif ($error === null && preg_match('/\A(?:Error|TimeoutError):/', $line) === 1) {
                $error = $line;
            }
        }

        return SignalText::compress(implode(', ', $counts) . ($error !== null ? ' — ' . $error : ''));
    }

    private static function notRun(VerificationTarget $target, string $why): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, $why);
    }
}
