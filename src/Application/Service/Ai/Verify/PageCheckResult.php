<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Reads what the browser saw on a page {@see PageCheckTargets} named.
 *
 * `bin/semitexa ai:verify` opens the pages on the host, in the e2e-runner, and
 * the page-check spec (resources/page-check) files one JSON per page under
 * var/run/verify-e2e/<run>/pages/<sha256 of the path>.json:
 *
 *     {"path": "/", "modes": {"light": {"status": 200, "html": true,
 *       "findings": ["color-contrast x3", "no skin: --ui-surface-page is not set"]}, "dark": {…}}}
 *
 * Both modes are required: on 2026-11-07 light passed and dark held light text
 * on white. A page with no result, or one the browser could not reach, is
 * incomplete and says why: a gate that cannot check must not pass.
 */
final readonly class PageCheckResult
{
    public const MODES = ['light', 'dark'];

    public function __construct(
        private string $projectRoot,
        private ?string $runId,
    ) {}

    public static function fromEnvironment(string $projectRoot): self
    {
        $run = getenv(E2eSpecResultCheck::RUN_ENV);

        return new self($projectRoot, is_string($run) && $run !== '' ? $run : null);
    }

    public static function fileName(string $path): string
    {
        return hash('sha256', $path) . '.json';
    }

    public function check(VerificationTarget $target): VerificationResult
    {
        $path = (string) ($target->commandInput['path'] ?? '');
        if ($this->runId === null || preg_match('/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,63}\z/', $this->runId) !== 1) {
            return self::notRun($target, "{$path} was NOT opened: pages are opened on the host, and this verification was not started by bin/semitexa ai:verify");
        }

        $dir = $this->projectRoot . '/' . E2eSpecResultCheck::RESULTS_DIR . '/' . $this->runId;
        $raw = @file_get_contents("{$dir}/pages/" . self::fileName($path));
        $seen = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($seen) || !is_array($seen['modes'] ?? null)) {
            $host = @file_get_contents("{$dir}/pages.txt");
            $log = is_file("{$dir}/pages.log") ? 'the page check filed nothing for it; its log ends: ' . SignalText::lastLine((string) file_get_contents("{$dir}/pages.log")) : null;
            $host = is_string($host) && trim($host) !== '' ? $host : ($log ?? @file_get_contents("{$dir}/host.txt"));
            $why = is_string($host) && trim($host) !== '' ? trim($host) : 'the host filed nothing for it (the runner did not start, or the page check failed before it)';

            return self::notRun($target, "{$path} was NOT opened: {$why}");
        }

        $found = [];
        $missing = [];
        $html = true;
        foreach (self::MODES as $mode) {
            $view = $seen['modes'][$mode] ?? null;
            $status = is_array($view) && is_int($view['status'] ?? null) ? $view['status'] : 0;
            if (!is_array($view) || $status === 0) {
                $missing[] = !is_array($view) ? "not opened in {$mode}" : "could not be reached in {$mode}: " . SignalText::compress((string) ($view['error'] ?? 'no response'));
                continue;
            }
            if ($status >= 400) {
                $found[] = "{$mode}: HTTP {$status}";
            }
            // A result has to say what was checked: a bare status is no evidence (review dev#216).
            if (!is_bool($view['html'] ?? null) || !is_array($view['findings'] ?? null) || !is_array($view['stylesheets'] ?? null)) {
                $missing[] = "a malformed result in {$mode}";
                continue;
            }
            if ($view['html'] === false) {
                $html = false;
                continue;
            }
            // Sent elsewhere (a sign-in page, say): what was looked at is not this page.
            $landed = is_string($view['landed'] ?? null) ? $view['landed'] : $path;
            if (rtrim($landed, '/') !== rtrim($path, '/')) {
                $missing[] = "redirected to {$landed} in {$mode}";
                continue;
            }
            foreach ($view['findings'] as $finding) {
                $found[] = "{$mode}: " . (string) $finding;
            }
        }

        if ($missing !== []) {
            // A finding already seen is a failure, whatever the other mode could not show (review dev#216).
            return $found !== []
                ? new VerificationResult($target, VerificationResult::STATUS_FAIL, 1, SignalText::compress("{$path} — " . implode('; ', array_slice($found, 0, 6)) . ' (and ' . implode('; ', $missing) . ')'))
                : self::notRun($target, "{$path} was NOT checked: " . implode('; ', $missing));
        }

        if (!$html && $found === []) {
            // An error is a failure whatever its body; a working non-HTML answer is nothing a person reads.
            return new VerificationResult($target, VerificationResult::STATUS_SKIPPED, 0, "{$path} is not an HTML page: nothing a person reads");
        }

        $unloaded = self::unloaded($target->triggeredBy, $seen['modes']['light']['stylesheets'] ?? null);
        if ($unloaded !== []) {
            // Only stylesheets changed, and this page loads none of them: whatever it shows, it is not the change.
            return self::notRun($target, "{$path} does not load " . implode(', ', $unloaded) . ': it cannot show that change' . ($found !== [] ? ' (it has findings of its own: ' . SignalText::compress(implode('; ', array_slice($found, 0, 3))) . ')' : ''));
        }

        if ($found === []) {
            return new VerificationResult($target, VerificationResult::STATUS_PASS, 0, "{$path} opened in light and dark: no contrast, label or skin finding");
        }

        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_FAIL,
            exitCode: 1,
            signal:   SignalText::compress("{$path} — " . implode('; ', array_slice($found, 0, 6)) . (count($found) > 6 ? sprintf('; and %d more', count($found) - 6) : '')),
        );
    }

    /**
     * The changed stylesheets the page did not load, when stylesheets are all that changed.
     * A template change cannot be told from the page, so it is never reported here.
     *
     * @param list<string> $triggers
     * @return list<string> their file names
     */
    private static function unloaded(array $triggers, mixed $loaded): array
    {
        if ($triggers === [] || !is_array($loaded)) {
            return [];
        }
        $names = [];
        foreach ($triggers as $file) {
            if (!str_ends_with($file, '.css')) {
                return [];
            }
            $names[] = basename($file);
        }
        $hrefs = array_map(static fn (mixed $href): string => basename((string) parse_url((string) $href, PHP_URL_PATH)), $loaded);

        return array_values(array_diff($names, $hrefs)) === $names ? $names : [];
    }

    private static function notRun(VerificationTarget $target, string $why): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, $why);
    }
}
