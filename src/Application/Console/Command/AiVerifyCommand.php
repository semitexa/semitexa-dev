<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFile;
use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationResult;
use Semitexa\Dev\Application\Service\Ai\Verify\VerificationTarget;
use Semitexa\Dev\Application\Service\Ai\Verify\VerifyReportSerializer;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\ImpactProbe;
use Semitexa\Dev\Application\Service\Ai\Verify\Impact\ImpactReport;
use Semitexa\Orm\Application\Service\Connection\ConnectionRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

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
            ->addOption('dirty', null, InputOption::VALUE_NONE, 'Every uncommitted change this workspace can see: each packages/semitexa-* repository, plus the project root when it is one. Says which roots it could not ask.')
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
            // `--dirty` finding nothing is an ANSWER, not a misuse: the tree is
            // clean. Telling that caller to "pass --dirty" is advice they just
            // took, and failing the run would make `ai:verify --dirty` red on
            // every clean checkout. It is not a pass either — nothing was
            // verified — so it gets a verdict of its own, with the scan's reach
            // attached so the reader can see what was asked.
            if ((bool) $input->getOption('dirty')) {
                $scan = (new DirtyWorkspaceScanner($this->getProjectRoot()))->report();

                // In the SAME shape the mode promises. Default mode is NDJSON
                // whose records are dispatched by `kind`, so an envelope
                // without one is a record such a consumer cannot place — and
                // every ordinary run ends with a `verdict` record.
                // An empty plan, so this answer reaches `--trace` the way every
                // other one does. Returning before maybeAppendToTrace() left a
                // traced workflow with no `verify_result` and no trace status
                // for the run at all — a gap in an audit trail reads as a step
                // that was never taken. Raised in review of dev#84.
                $emptyPlan = new VerificationPlan($scope, $scope, [], []);
                $envelope = [
                    'artifact' => 'semitexa-dev.verify-report/v1',
                    'generated_at' => date('c'),
                    'verdict' => 'nothing_to_verify',
                    'changed_files' => [],
                    'dirty_scan' => $scan,
                ];

                if ($jsonMode) {
                    $traceOutput = new BufferedOutput();
                    $this->maybeAppendToTrace($input, $traceOutput, $emptyPlan, [], 'nothing_to_verify', $envelope);
                    $envelope['trace'] = array_map(
                        static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                        array_values(array_filter(explode("\n", trim($traceOutput->fetch())))),
                    );
                    $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));

                    return self::SUCCESS;
                }

                // The same two records a run WITH changes emits, so a consumer
                // reads the scan out of one place regardless of the answer.
                $output->writeln(json_encode(['kind' => 'dirty_scan'] + $scan, JSON_UNESCAPED_SLASHES));
                $output->writeln(json_encode([
                    'kind' => 'verdict',
                    'verdict' => 'nothing_to_verify',
                    // FALSE, and deliberately so. Nothing ran, and `completed`
                    // is what a consumer reads to decide whether the required
                    // checks happened — saying true here offered an empty run
                    // as verification evidence, and contradicted
                    // VerifyReportSerializer::completed([]) besides. Raised in
                    // review of dev#84.
                    'completed' => false,
                    'counts' => [],
                    'dirty_scan' => $scan,
                ], JSON_UNESCAPED_SLASHES));

                $this->maybeAppendToTrace($input, $output, $emptyPlan, [], 'nothing_to_verify', $envelope);

                return self::SUCCESS;
            }

            $this->emitError($output, 'no changed files supplied — pass --files, --git-ref, --diff-stdin or --dirty', $jsonMode);
            return self::FAILURE;
        }

        $projectRoot = $this->getProjectRoot();
        $classifier = new ChangedFileClassifier();
        $changed = array_map(
            static fn(array $entry): ChangedFile => $classifier->classify(
                $entry['path'],
                $entry['status'],
                ($entry['originalPath'] ?? '') !== '' ? $entry['originalPath'] : null,
            ),
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
        $exit = in_array($verdict, [VerificationResult::STATUS_PASS, VerificationResult::STATUS_SKIPPED], true) ? self::SUCCESS : self::FAILURE;

        $impact = null;
        if ((bool) $input->getOption('impact')) {
            $impact = $this->probeImpact($plan);
        }

        $envelope = $this->buildEnvelope($plan, $results, $verdict, $impact);
        $dirtyScan = null;
        if ((bool) $input->getOption('dirty')) {
            // The reach of the answer, beside the answer. A scan that could not
            // ask half the tree must not read as "half the tree is clean".
            $dirtyScan = (new DirtyWorkspaceScanner($this->getProjectRoot()))->report();
            $envelope['dirty_scan'] = $dirtyScan;
        }
        if ($jsonMode) {
            $traceOutput = new BufferedOutput();
            $this->maybeAppendToTrace($input, $traceOutput, $plan, $results, $verdict, $envelope);
            $envelope['trace'] = array_map(
                static fn(string $line): mixed => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                array_values(array_filter(explode("\n", trim($traceOutput->fetch())))),
            );
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
        } else {
            $this->emitNdjson($output, $plan, $results, $verdict, $impact, $dirtyScan);
            $this->maybeAppendToTrace($input, $output, $plan, $results, $verdict, $envelope);
        }

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
        $report = new VerifyReportSerializer();
        $counts = $report->countByStatus($results);
        $summary = sprintf(
            'verify %s — scope=%s targets=%d pass=%d fail=%d skipped=%d incomplete=%d',
            $verdict,
            $plan->effectiveScope,
            count($plan->targets),
            $counts['pass'] ?? 0,
            $counts['fail'] ?? 0,
            $counts['skipped'] ?? 0,
            $counts['incomplete'] ?? 0,
        );
        $this->traceAppender->appendIfActive($input, $output, TraceEventKind::VERIFY_RESULT, $summary, $envelope);
    }

    /**
     * @return list<array{path: string, status: string, originalPath?: string}>
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
        if ((bool) $input->getOption('dirty')) {
            foreach ((new DirtyWorkspaceScanner($this->getProjectRoot()))->changedFiles() as $entry) {
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
     * @return list<array{path: string, status: string, originalPath?: string}>
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
     * @return list<array{path: string, status: string, originalPath?: string}>
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
     * @param list<array{path: string, status: string, originalPath?: string}> $entries
     * @return list<array{path: string, status: string, originalPath?: string}>
     */
    private function dedupe(array $entries): array
    {
        $seen = [];
        $out = [];
        foreach ($entries as $entry) {
            $key = $entry['path'];
            if (isset($seen[$key])) {
                // First-wins, EXCEPT for what the first one does not know.
                // `--files=<renamed destination> --dirty` supplies the path
                // manually as a plain modification and the scanner then finds
                // the same path as a rename; dropping the second entry whole
                // discarded `originalPath`, so ContractMoveResolver never
                // expanded consumers of the old contract and the run came back
                // green with stale references in it. A rename is strictly more
                // than a modification of the same file, so it wins. Raised in
                // review of dev#84.
                $at = $seen[$key];
                $original = $entry['originalPath'] ?? '';
                if ($original !== '' && ($out[$at]['originalPath'] ?? '') === '') {
                    $out[$at] = [
                        'path' => $key,
                        'status' => $entry['status'],
                        'originalPath' => $original,
                    ];
                }
                continue;
            }
            $seen[$key] = count($out);
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param list<VerificationResult> $results
     */
    private function verdict(array $results): string
    {
        $report = new VerifyReportSerializer();
        if ($results === []) {
            return VerificationResult::STATUS_INCOMPLETE;
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
        if (!$report->completed($results)) {
            return VerificationResult::STATUS_INCOMPLETE;
        }
        return $allSkipped ? VerificationResult::STATUS_SKIPPED : VerificationResult::STATUS_PASS;
    }

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
        $report = new VerifyReportSerializer();
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
            'targets'         => array_map(fn($t) => $report->serializeTarget($t), $plan->targets),
            'results'         => array_map(fn($r) => $report->serializeResult($r), $results),
            'violations'      => $violations,
            'verdict'         => $verdict,
            'completed'       => $report->completed($results),
            'counts'          => $report->countByStatus($results),
            'impact'          => $impact?->toSummary(),
            'next_command'    => $this->buildNextCommands($verdict, $results),
            'restart'         => $report->restartAdvice($plan->changedFiles),
        ];
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
        if (in_array($verdict, [VerificationResult::STATUS_FAIL, VerificationResult::STATUS_INCOMPLETE], true)) {
            $failingFile = null;
            $hasStructureFailure = false;
            $hasDiFailure = false;
            foreach ($results as $r) {
                if (in_array($r->status, [VerificationResult::STATUS_FAIL, VerificationResult::STATUS_INCOMPLETE], true)) {
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
                $out[] = ['cmd' => 'ai:ask', 'args' => ['path', '--path=' . $failingFile, '--json'], 'why' => 'inspect the failing file before querying symbol impact'];
            }
            $out[] = ['cmd' => 'logs:app', 'args' => ['--grep=error', '--lines=200', '--level=ERROR', '--json'], 'why' => 'runtime errors that might explain the failure'];
            return $out;
        }
        return [
            ['cmd' => 'ai:verify', 'args' => ['--files=<paths>', '--scope=standard', '--json'], 'why' => 'no actionable verification ran — select files and try standard scope'],
        ];
    }

    /**
     * @param list<VerificationResult> $results
     * @param array{scanned: list<string>, unscannable: list<string>}|null $dirtyScan
     */
    private function emitNdjson(
        OutputInterface $output,
        VerificationPlan $plan,
        array $results,
        string $verdict,
        ?ImpactReport $impact = null,
        ?array $dirtyScan = null,
    ): void {
        $report = new VerifyReportSerializer();
        // NDJSON is the DEFAULT mode, and the reach of a `--dirty` scan existed
        // only in the `--json` envelope -- so a run that could not ask half the
        // tree said so exclusively to the readers who had not asked for this
        // mode. A record of its own, dispatchable by `kind` like every other.
        // Raised in review of dev#84.
        if ($dirtyScan !== null) {
            $output->writeln(json_encode(
                ['kind' => 'dirty_scan'] + $dirtyScan,
                JSON_UNESCAPED_SLASHES,
            ));
        }

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
                'target' => $report->serializeTarget($target),
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($results as $result) {
            $output->writeln(json_encode([
                'kind'   => 'result',
                'result' => $report->serializeResult($result),
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
            ['kind' => 'restart'] + $report->restartAdvice($plan->changedFiles),
            JSON_UNESCAPED_SLASHES,
        ));

        $verdictLine = [
            'kind'    => 'verdict',
            'verdict' => $verdict,
            'completed' => $report->completed($results),
            'counts'  => $report->countByStatus($results),
        ];
        if ($impact !== null) {
            $verdictLine['impact'] = $impact->toSummary();
        }
        $output->writeln(json_encode($verdictLine, JSON_UNESCAPED_SLASHES));
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
