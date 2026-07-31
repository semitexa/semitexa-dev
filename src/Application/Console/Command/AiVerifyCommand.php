<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\ImpactProbe;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\ImpactReport;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Agent-facing verifier: takes a diff or file list, plans the precise lint /
 * syntax / phpunit / module-structure subset to run, executes it, and emits
 * an NDJSON envelope.
 *
 *   bin/semitexa ai:verify --files=src/modules/Foo/src/Application/Handler/PayloadHandler/Bar.php
 *   bin/semitexa ai:verify --git-ref=HEAD~1 --scope=standard
 *   git diff --name-only HEAD~1 | bin/semitexa ai:verify --diff-stdin
 *
 * Output (NDJSON, one JSON object per line):
 *   {"kind":"summary",   recipe-style header}
 *   {"kind":"expansion", note-of-why-scope-bumped}        (zero or more)
 *   {"kind":"target",    pre-execution per-target metadata}
 *   {"kind":"result",    post-execution per-target outcome}
 *   {"kind":"violation", per-target structured diagnostic} (zero or more)
 *   {"kind":"verdict",   pass/fail rollup}
 *
 * `--json` mode flips the output into a single envelope (artifact:
 * `semitexa-dev.verify-report/v1`) for callers that prefer one blob over a
 * stream — both modes carry exactly the same data, including the
 * `violations` aggregate from the `module_structure` check (rules and
 * remediation guidance live in
 * `packages/semitexa-docs/docs/MODULE_STRUCTURE.md`).
 */
#[AsCommand(name: 'ai:verify', description: 'Run the precise lint+test+module-structure subset for a diff/file list (NDJSON, agent-facing)')]
final class AiVerifyCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TraceAutoAppender $traceAppender;

    // Optional (nullable) so ai:verify still runs where no DB is bound
    // (e.g. the command's own test harness); only --impact needs it.
    #[InjectAsReadonly]
    protected ?ConnectionRegistry $connections = null;

    public function __construct()
    {
        parent::__construct('ai:verify');
    }

    protected function configure(): void
    {
        $this
            ->addOption('files', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Repo-relative path(s) to verify. Repeat the flag and/or comma-separate; both forms combine.')
            ->addOption('git-ref', null, InputOption::VALUE_REQUIRED, 'Compare working tree against this git ref (e.g. HEAD~1, origin/main)')
            ->addOption('diff-stdin', null, InputOption::VALUE_NONE, 'Read newline-separated paths from stdin (output of `git diff --name-only`)')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Scan every Semitexa package under packages/semitexa-* and every local module under src/modules/* (deterministic repo-wide module-structure check)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Verification scope: minimal, standard, broad', VerificationPlan::SCOPE_STANDARD)
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a verify_result event to this ai:trace id (falls back to $SEMITEXA_AI_TRACE_ID)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON')
            ->addOption('impact', null, InputOption::VALUE_NONE, 'Annotate each changed file with a project-graph blast-radius band (low|medium|high). Read-only: does not change which checks run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jsonMode = (bool) $input->getOption('json');
        $scope = (string) $input->getOption('scope');

        try {
            $paths = $this->collectPaths($input);
        } catch (\RuntimeException $e) {
            $this->emitError($output, $e->getMessage(), $jsonMode);
            return self::FAILURE;
        }

        if ($paths === []) {
            $this->emitError($output, 'no changed files supplied — pass --files, --git-ref, or --diff-stdin', $jsonMode);
            return self::FAILURE;
        }

        $projectRoot = $this->getProjectRoot();
        $classifier = new ChangedFileClassifier();
        $changed = array_map(
            static function (array $entry) use ($classifier): ChangedFile {
                $file = $classifier->classify($entry['path'], $entry['status']);
                if (isset($entry['originalPath']) && $entry['originalPath'] !== '') {
                    $file->originalPath = $entry['originalPath'];
                }
                return $file;
            },
            $paths,
        );
        /** @var list<ChangedFile> $changed */

        $planner = new VerificationPlanner($projectRoot, $classifier);
        $plan = $planner->plan($changed, $scope, (bool) $input->getOption('all'));

        $app = $this->getApplication();
        if ($app === null) {
            $this->emitError($output, 'Application not available — cannot dispatch lint commands', $jsonMode);
            return self::FAILURE;
        }
        $executor = new VerificationExecutor($app, $projectRoot);
        $results = $executor->execute($plan);

        $verdict = $this->verdict($results);
        $exit = $verdict === VerificationResult::STATUS_FAIL ? self::FAILURE : self::SUCCESS;

        $impact = null;
        if ((bool) $input->getOption('impact')) {
            $impact = $this->probeImpact($plan);
        }

        $envelope = $this->buildEnvelope($plan, $results, $verdict, $impact);
        if ($jsonMode) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
        } else {
            $this->emitNdjson($output, $plan, $results, $verdict, $impact);
        }

        $this->maybeAppendToTrace($input, $output, $plan, $results, $verdict, $envelope);

        return $exit;
    }

    /**
     * @param list<VerificationResult> $results
     * @param array<string, mixed>     $envelope
     */
    private function maybeAppendToTrace(
        InputInterface $input,
        OutputInterface $output,
        VerificationPlan $plan,
        array $results,
        string $verdict,
        array $envelope,
    ): void {
        $counts = $this->countByStatus($results);
        $summary = sprintf(
            'verify %s — scope=%s targets=%d pass=%d fail=%d skipped=%d',
            $verdict,
            $plan->effectiveScope,
            count($plan->targets),
            $counts['pass'] ?? 0,
            $counts['fail'] ?? 0,
            $counts['skipped'] ?? 0,
        );
        $this->traceAppender->appendIfActive($input, $output, TraceEventKind::VERIFY_RESULT, $summary, $envelope);
    }

    /**
     * @return list<array{path: string, status: string}>
     */
    private function collectPaths(InputInterface $input): array
    {
        $sources = [];
        // --files is VALUE_IS_ARRAY: repeated flags arrive as a list, and each
        // element may itself be a comma-separated batch. Both forms combine, so
        // no path is ever silently dropped (the repeated-flag form previously
        // kept only the last value and returned a false-green partial verify).
        foreach ((array) $input->getOption('files') as $files) {
            foreach (explode(',', (string) $files) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $sources[] = ['path' => $p, 'status' => ChangedFile::STATUS_MODIFIED];
                }
            }
        }
        if (($ref = $input->getOption('git-ref')) !== null && $ref !== '') {
            foreach ($this->gitDiffNameStatus((string) $ref) as $entry) {
                $sources[] = $entry;
            }
        }
        if ((bool) $input->getOption('diff-stdin')) {
            foreach ($this->readStdinPaths() as $entry) {
                $sources[] = $entry;
            }
        }
        if ((bool) $input->getOption('all')) {
            foreach ($this->repoWidePaths() as $entry) {
                $sources[] = $entry;
            }
        }
        return $this->dedupe($sources);
    }

    /**
     * Repo-wide module / package roots: every `packages/semitexa-X` whose
     * package root contains `composer.json`, plus every `src/modules/X`.
     * Used by `--all` for deterministic AI-facing repo-wide structure
     * verification. Output is sorted lexicographically so NDJSON is stable.
     *
     * @return list<array{path: string, status: string}>
     */
    private function repoWidePaths(): array
    {
        $root = rtrim($this->getProjectRoot(), '/');
        $paths = [];

        $packagesDir = $root . '/packages';
        if (is_dir($packagesDir)) {
            foreach (glob($packagesDir . '/semitexa-*', GLOB_ONLYDIR) ?: [] as $abs) {
                if (!is_file($abs . '/composer.json')) {
                    continue;
                }
                $rel = ltrim(substr($abs, strlen($root)), '/');
                $paths[] = $rel;
            }
        }

        $modulesDir = $root . '/src/modules';
        if (is_dir($modulesDir)) {
            foreach (glob($modulesDir . '/*', GLOB_ONLYDIR) ?: [] as $abs) {
                $rel = ltrim(substr($abs, strlen($root)), '/');
                $paths[] = $rel;
            }
        }

        sort($paths);
        return array_map(
            static fn(string $p) => ['path' => $p, 'status' => ChangedFile::STATUS_MODIFIED],
            $paths,
        );
    }

    /**
     * @return list<array{path: string, status: string}>
     */
    private function gitDiffNameStatus(string $ref): array
    {
        $cmd = sprintf(
            'git -C %s diff --name-status %s 2>&1',
            escapeshellarg($this->getProjectRoot()),
            escapeshellarg($ref),
        );
        exec($cmd, $lines, $code);
        if ($code !== 0) {
            throw new \RuntimeException("git diff against '{$ref}' failed: " . implode(' / ', $lines));
        }
        return $this->parseNameStatus($lines);
    }

    /**
     * @return list<array{path: string, status: string}>
     */
    private function readStdinPaths(): array
    {
        $raw = (string) stream_get_contents(STDIN);
        $lines = preg_split('/\R/', trim($raw)) ?: [];
        return $this->parseNameStatus($lines);
    }

    /**
     * @param list<string> $lines git --name-status output OR plain `git diff --name-only` lines
     * @return list<array{path: string, status: string, originalPath?: string}>
     */
    private function parseNameStatus(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 3) ?: [];
            if (count($parts) >= 2 && preg_match('/^[AMDR][0-9]*$/', $parts[0])) {
                $status = match ($parts[0][0]) {
                    'A' => ChangedFile::STATUS_ADDED,
                    'M' => ChangedFile::STATUS_MODIFIED,
                    'D' => ChangedFile::STATUS_DELETED,
                    'R' => ChangedFile::STATUS_RENAMED,
                    default => ChangedFile::STATUS_MODIFIED,
                };
                // For R entries, `git diff --name-status` emits OLD<TAB>NEW; take the
                // new path AND carry the old path so the broken-FQCN guard can query
                // the renamed symbol's previous FQCN too.
                $path = $parts[count($parts) - 1];
                $entry = ['path' => $path, 'status' => $status];
                if ($status === ChangedFile::STATUS_RENAMED && count($parts) >= 3) {
                    $entry['originalPath'] = $parts[1];
                }
                $out[] = $entry;
                continue;
            }
            $out[] = ['path' => $line, 'status' => ChangedFile::STATUS_MODIFIED];
        }
        return $out;
    }

    /**
     * @param list<array{path: string, status: string}> $entries
     * @return list<array{path: string, status: string}>
     */
    private function dedupe(array $entries): array
    {
        $seen = [];
        $out = [];
        foreach ($entries as $entry) {
            $key = $entry['path'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param list<VerificationResult> $results
     */
    private function verdict(array $results): string
    {
        if ($results === []) {
            return VerificationResult::STATUS_PASS;
        }
        $hasFail = false;
        $allSkipped = true;
        foreach ($results as $r) {
            if ($r->status === VerificationResult::STATUS_FAIL) {
                $hasFail = true;
            }
            if ($r->status !== VerificationResult::STATUS_SKIPPED) {
                $allSkipped = false;
            }
        }
        if ($hasFail) {
            return VerificationResult::STATUS_FAIL;
        }
        return $allSkipped ? VerificationResult::STATUS_SKIPPED : VerificationResult::STATUS_PASS;
    }

    /**
     * @param list<VerificationResult> $results
     * @return array<string, mixed>
     */
    /**
     * Read-only blast-radius probe. Never throws into the verify flow — a
     * graph problem must degrade to an "unknown" band, not fail verification.
     */
    private function probeImpact(VerificationPlan $plan): ImpactReport
    {
        $paths = [];
        foreach ($plan->changedFiles as $file) {
            if (str_ends_with($file->path, '.php')) {
                $paths[] = $file->path;
            }
        }
        if ($paths === []) {
            return ImpactReport::empty();
        }

        if ($this->connections === null) {
            return ImpactReport::stale($paths, 'impact unavailable: no database connection bound in this context');
        }

        try {
            return (new ImpactProbe($this->connections))->probe(array_values($paths));
        } catch (\Throwable $e) {
            return ImpactReport::stale($paths, 'impact probe failed: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, string> path → band, for merging into changed_files
     */
    private function bandsByPath(?ImpactReport $impact): array
    {
        if ($impact === null) {
            return [];
        }
        $bands = [];
        foreach ($impact->files as $file) {
            $bands[$file->path] = $file->band;
        }
        return $bands;
    }

    private function buildEnvelope(VerificationPlan $plan, array $results, string $verdict, ?ImpactReport $impact = null): array
    {
        $violations = $this->collectViolations($results);
        $bands = $this->bandsByPath($impact);
        return [
            'artifact'        => 'semitexa-dev.verify-report/v1',
            'generated_at'    => date('c'),
            'requested_scope' => $plan->scope,
            'effective_scope' => $plan->effectiveScope,
            'expansions'      => $plan->expansions,
            'changed_files'   => array_map(
                static function (ChangedFile $f) use ($bands): array {
                    $entry = ['path' => $f->path, 'kind' => $f->kind, 'status' => $f->status];
                    if (isset($bands[$f->path])) {
                        $entry['impact'] = $bands[$f->path];
                    }
                    return $entry;
                },
                $plan->changedFiles,
            ),
            'targets'         => array_map(fn($t) => $this->serializeTarget($t), $plan->targets),
            'results'         => array_map(fn($r) => $this->serializeResult($r), $results),
            'violations'      => $violations,
            'verdict'         => $verdict,
            'counts'          => $this->countByStatus($results),
            'impact'          => $impact?->toSummary(),
            'next_command'    => $this->buildNextCommands($verdict, $results),
            'restart'         => $this->restartAdvice($plan->changedFiles),
        ];
    }

    /**
     * Whether a server restart is needed, and for what.
     *
     * Verification never needs one. Every check here runs in a fresh CLI
     * process that rediscovers classes from disk, so a file saved a second ago
     * is already visible — measured by having a brand-new class fail this
     * command with no restart in between.
     *
     * Saying so matters because the habit is expensive and invisible. A
     * consumer agent reported a 30-60s verify cycle as its single biggest time
     * sink, having wrapped every run in `server:restart` + `cache:clear`. On
     * this machine that is 16s of restart around 4s of actual verification —
     * four fifths of the cycle spent on a step that changed nothing. Nothing in
     * the output contradicted the assumption, so it never got questioned.
     *
     * A restart IS needed before exercising the RUNNING server, because Swoole
     * workers hold discovered classes and compiled templates for the life of
     * the worker. That is a different activity from verifying, and the advice
     * only appears when the changed files are the kind the running server
     * caches.
     *
     * @param list<ChangedFile> $changedFiles
     * @return array{needed_for_verification: bool, note: string, before_browsing?: string}
     */
    private function restartAdvice(array $changedFiles): array
    {
        $advice = [
            'needed_for_verification' => false,
            'note' => 'Verification reads files from disk in a fresh process; '
                . 'no restart was needed and none would have changed this result.',
        ];

        $affectsRunningServer = false;
        foreach ($changedFiles as $file) {
            if (in_array($file->kind, [
                ChangedFile::KIND_TEMPLATE,
                ChangedFile::KIND_CLIENT_SCRIPT,
            ], true) || str_ends_with($file->path, '.php')) {
                $affectsRunningServer = true;
                break;
            }
        }

        if ($affectsRunningServer) {
            $advice['before_browsing'] = 'bin/semitexa server:restart — required only before exercising '
                . 'the running server (browser, curl, E2E): workers cache discovered classes and '
                . 'compiled templates for their lifetime.';
        }

        return $advice;
    }

    /**
     * @param list<VerificationResult> $results
     * @return list<array<string, mixed>>
     */
    private function collectViolations(array $results): array
    {
        $out = [];
        foreach ($results as $r) {
            foreach ($r->diagnostics as $diag) {
                $out[] = $diag;
            }
        }
        return $out;
    }

    /**
     * @param list<VerificationResult> $results
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildNextCommands(string $verdict, array $results): array
    {
        if ($verdict === VerificationResult::STATUS_PASS) {
            return [
                ['cmd' => 'ai:work', 'args' => ['update', '--id=<task-id>', '--status=done', '--json'], 'why' => 'close the active task — verify passed'],
                ['cmd' => 'ai:orient', 'args' => ['--json'], 'why' => 'pick up the next task'],
            ];
        }
        if ($verdict === VerificationResult::STATUS_FAIL) {
            $failingFile = null;
            $hasStructureFailure = false;
            $hasDiFailure = false;
            foreach ($results as $r) {
                if ($r->status === VerificationResult::STATUS_FAIL) {
                    if ($r->target->type === VerificationTarget::TYPE_MODULE_STRUCTURE) {
                        $hasStructureFailure = true;
                    }
                    if ($r->target->type === VerificationTarget::TYPE_PHPSTAN_DI) {
                        $hasDiFailure = true;
                    }
                    if ($failingFile === null && $r->target->filePath !== null) {
                        $failingFile = $r->target->filePath;
                    }
                }
            }
            $out = [
                ['cmd' => 'ai:work', 'args' => ['update', '--id=<task-id>', '--status=blocked', '--note="verify failed"'], 'why' => 'record the block before iterating (§9.3)'],
            ];
            if ($hasStructureFailure) {
                $out[] = [
                    'cmd'  => 'cat',
                    'args' => ['packages/semitexa-docs/docs/MODULE_STRUCTURE.md'],
                    'why'  => 'module structure violations — re-read the rule table before moving files',
                ];
            }
            if ($hasDiFailure) {
                $out[] = [
                    'cmd'  => 'cat',
                    'args' => ['packages/semitexa-docs/docs/AI_BEST_PRACTICES.md'],
                    'why'  => 'DI violations — Semitexa is attribute-only DI; re-read before reshaping a class',
                ];
            }
            if ($failingFile !== null) {
                $out[] = ['cmd' => 'ai:review-graph:impact', 'args' => [$failingFile, '--json'], 'why' => 'find callers affected by the failing file'];
            }
            $out[] = ['cmd' => 'logs:app', 'args' => ['--grep=error', '--lines=200', '--level=ERROR', '--json'], 'why' => 'runtime errors that might explain the failure'];
            return $out;
        }
        return [
            ['cmd' => 'ai:verify', 'args' => ['--scope=standard', '--json'], 'why' => 'no actionable verification ran — try standard scope'],
        ];
    }

    /**
     * @param list<VerificationResult> $results
     */
    private function emitNdjson(OutputInterface $output, VerificationPlan $plan, array $results, string $verdict, ?ImpactReport $impact = null): void
    {
        $output->writeln(json_encode([
            'kind'            => 'summary',
            'requested_scope' => $plan->scope,
            'effective_scope' => $plan->effectiveScope,
            'changed_files'   => count($plan->changedFiles),
            'targets'         => count($plan->targets),
        ], JSON_UNESCAPED_SLASHES));

        if ($impact !== null) {
            $output->writeln(json_encode([
                'kind'   => 'impact',
                'impact' => $impact->toSummary(),
                'files'  => array_map(static fn ($f) => $f->toArray(), $impact->files),
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($plan->expansions as $note) {
            $output->writeln(json_encode([
                'kind' => 'expansion',
                'note' => $note,
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($plan->targets as $target) {
            $output->writeln(json_encode([
                'kind'   => 'target',
                'target' => $this->serializeTarget($target),
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($results as $result) {
            $output->writeln(json_encode([
                'kind'   => 'result',
                'result' => $this->serializeResult($result),
            ], JSON_UNESCAPED_SLASHES));
            foreach ($result->diagnostics as $diagnostic) {
                $output->writeln(json_encode([
                    'kind'      => 'violation',
                    'target_id' => $result->target->id,
                    ...$diagnostic,
                ], JSON_UNESCAPED_SLASHES));
            }
        }

        // Before the verdict, so it is read rather than scrolled past.
        //
        // The advice existed only in the `--json` envelope, and NDJSON is the
        // default — so the habit this was written to correct (wrapping every
        // verify in a 16s server:restart) never met the sentence that corrects
        // it. An unstated assumption is not fixed by being wrong somewhere the
        // reader does not look.
        $output->writeln((string) json_encode(
            ['kind' => 'restart'] + $this->restartAdvice($plan->changedFiles),
            JSON_UNESCAPED_SLASHES,
        ));

        $verdictLine = [
            'kind'    => 'verdict',
            'verdict' => $verdict,
            'counts'  => $this->countByStatus($results),
        ];
        if ($impact !== null) {
            $verdictLine['impact'] = $impact->toSummary();
        }
        $output->writeln(json_encode($verdictLine, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTarget(VerificationTarget $target): array
    {
        return [
            'id'           => $target->id,
            'type'         => $target->type,
            'reason'       => $target->reason,
            'triggered_by' => $target->triggeredBy,
            'command_name' => $target->commandName,
            'file_path'    => $target->filePath,
            'test_filter'  => $target->testFilter,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeResult(VerificationResult $result): array
    {
        $out = [
            'id'        => $result->target->id,
            'type'      => $result->target->type,
            'status'    => $result->status,
            'exit_code' => $result->exitCode,
            'signal'    => $result->signal,
        ];
        if ($result->diagnostics !== []) {
            $out['diagnostics_count'] = count($result->diagnostics);
        }
        return $out;
    }

    /**
     * @param list<VerificationResult> $results
     * @return array<string, int>
     */
    private function countByStatus(array $results): array
    {
        $counts = ['pass' => 0, 'fail' => 0, 'skipped' => 0];
        foreach ($results as $r) {
            $counts[$r->status] = ($counts[$r->status] ?? 0) + 1;
        }
        return $counts;
    }

    private function emitError(OutputInterface $output, string $message, bool $jsonMode): void
    {
        if ($jsonMode) {
            $output->writeln(json_encode([
                'artifact'     => 'semitexa-dev.verify-report/v1',
                'generated_at' => date('c'),
                'verdict'      => 'fail',
                'error'        => $message,
            ], JSON_UNESCAPED_SLASHES));
            return;
        }
        $output->writeln(json_encode([
            'kind'  => 'error',
            'error' => $message,
        ], JSON_UNESCAPED_SLASHES));
    }
}
