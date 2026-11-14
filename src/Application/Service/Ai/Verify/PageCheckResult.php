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
        $fragment = true;
        foreach (self::MODES as $mode) {
            $view = $seen['modes'][$mode] ?? null;
            if (is_array($view) && !is_int($view['status'] ?? null)) {
                $missing[] = "a malformed result in {$mode}";
                continue;
            }
            $status = is_array($view) ? (int) $view['status'] : 0;
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
                $fragment = $fragment && ($view['fragment'] ?? false) === true;
                continue;
            }
            $fragment = false;
            // Sent elsewhere (a sign-in page, say): what was looked at is not this page.
            $landed = is_string($view['landed'] ?? null) ? $view['landed'] : $path;
            if (rtrim(rawurldecode($landed), '/') !== rtrim(rawurldecode($path), '/')) {
                $missing[] = "redirected to {$landed} in {$mode}";
                continue;
            }
            foreach ($view['findings'] as $finding) {
                $found[] = "{$mode}: " . (string) $finding;
            }
        }

        // 404 in both modes for a route whose declaring file is newer than the
        // running server: its workers cannot have loaded it, so the page is not
        // broken, the server is behind (tk-page-check-stale-route; a Guard run
        // read "HTTP 404" as its own bug). Without that evidence a 404 stays the
        // failure it may be: an existing route that now answers 404 is one
        // (review, dev#226).
        $notFound = 0;
        foreach (self::MODES as $mode) {
            $notFound += (int) ((($seen['modes'][$mode] ?? null)['status'] ?? null) === 404);
        }
        if ($notFound === count(self::MODES) && $this->declaredSinceServerStart((string) ($target->commandInput['declared_in'] ?? ''))) {
            // New or edited, the server answers from that file as it was when it
            // started: its answer says nothing about this change (review, dev#226).
            return self::notRun($target, "{$path} answered 404 from the running server, but the file that declares the route changed after it started: "
                . 'the server answers from the code before that change, not this one. Restart it (bin/semitexa server:restart app) and verify again');
        }

        if ($missing !== []) {
            // A finding already seen is a failure, whatever the other mode could not show (review dev#216).
            return $found !== []
                ? new VerificationResult($target, VerificationResult::STATUS_FAIL, 1, SignalText::compress("{$path} — " . implode('; ', $missing) . '; ' . implode('; ', array_slice($found, 0, 6)) . (count($found) > 6 ? sprintf('; and %d more', count($found) - 6) : '')))
                : self::notRun($target, "{$path} was NOT checked: " . implode('; ', $missing));
        }

        if (!$html && $found === []) {
            // An error is a failure whatever its body; a working non-HTML answer is nothing a person reads.
            return new VerificationResult($target, VerificationResult::STATUS_SKIPPED, 0, $fragment
                ? "{$path} is an HTML fragment, not a page: it is seen inside the page that loads it"
                : "{$path} is not an HTML page: nothing a person reads");
        }

        // In both modes: a page may load a stylesheet for one colour scheme only (review dev#216).
        $unloaded = array_values(array_unique(array_merge(
            self::unloaded($target->triggeredBy, $seen['modes']['light']['stylesheets'] ?? null),
            self::unloaded($target->triggeredBy, $seen['modes']['dark']['stylesheets'] ?? null),
        )));
        // An error page loads none of the page's stylesheets: the error is the result (independent review).
        $erred = array_filter($found, static fn (string $f): bool => preg_match('/^(?:light|dark): HTTP \d+$/', $f) === 1) !== [];
        if ($unloaded !== [] && !$erred) {
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

    /** Whether $file changed after the running server started (its pid file is written at start). */
    private function declaredSinceServerStart(string $file): bool
    {
        $declared = $file !== '' ? @filemtime($file) : false;
        if ($declared === false) {
            return false;
        }
        foreach (['var/run/semitexa.pid', 'var/swoole.pid'] as $pid) {
            $started = @filemtime($this->projectRoot . '/' . $pid);
            if ($started !== false) {
                return $declared > $started;
            }
        }

        return false;
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
        $missing = [];
        foreach ($triggers as $file) {
            if (!str_ends_with($file, '.css')) {
                return [];
            }
            $served = self::servedAs($file);
            $isLoaded = false;
            foreach ($loaded as $href) {
                $hrefPath = (string) parse_url((string) $href, PHP_URL_PATH);
                // The asset's own path, not its name: another module's components.css is not this one (review dev#216).
                if (str_ends_with($hrefPath, '/' . $served)) {
                    $isLoaded = true;
                    break;
                }
            }
            if (!$isLoaded) {
                $missing[] = basename($file);
            }
        }

        // Any changed stylesheet the page loads makes it a page that can show the change.
        return count($missing) === count($triggers) ? $missing : [];
    }

    /**
     * The part of a changed stylesheet's path its served URL ends with: from skins/ for a
     * skin (served at /assets/skins/…), otherwise what follows Static/ (css/components.css),
     * which tells two modules' same-named files apart by directory. The asset prefix is not
     * the package's name (semitexa-theme serves layouts.css as theme-base), so it is not
     * compared.
     *
     */
    private static function servedAs(string $file): string
    {
        if (preg_match('#/(skins/.+)$#', $file, $m) === 1 || preg_match('#/(?:Static|static)/(.+)$#', $file, $m) === 1) {
            return $m[1];
        }

        return basename($file);
    }

    private static function notRun(VerificationTarget $target, string $why): VerificationResult
    {
        return new VerificationResult($target, VerificationResult::STATUS_INCOMPLETE, 1, $why);
    }
}
