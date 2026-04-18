<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Semitexa\Dev\Ai\Verify\ChangedFile;
use Semitexa\Dev\Ai\Verify\ChangedFileClassifier;
use Semitexa\Dev\Ai\Verify\VerificationExecutor;
use Semitexa\Dev\Ai\Verify\VerificationPlan;
use Semitexa\Dev\Ai\Verify\VerificationPlanner;
use Semitexa\Dev\Ai\Verify\VerificationResult;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Agent-facing verifier: takes a diff or file list, plans the precise lint /
 * syntax / phpunit subset to run, executes it, and emits an NDJSON envelope.
 *
 *   bin/semitexa ai:verify --files=src/modules/Foo/Application/Handler/PayloadHandler/Bar.php
 *   bin/semitexa ai:verify --git-ref=HEAD~1 --scope=standard
 *   git diff --name-only HEAD~1 | bin/semitexa ai:verify --diff-stdin
 *
 * Output (NDJSON, one JSON object per line):
 *   {"kind":"summary", recipe-style header}
 *   {"kind":"expansion", note-of-why-scope-bumped}        (zero or more)
 *   {"kind":"target",   pre-execution per-target metadata}
 *   {"kind":"result",   post-execution per-target outcome}
 *   {"kind":"verdict",  pass/fail rollup}
 *
 * `--json` mode flips the output into a single envelope (artifact:
 * `semitexa-dev.verify-report/v1`) for callers that prefer one blob over a
 * stream — both modes carry exactly the same data.
 */
#[AsCommand(name: 'ai:verify', description: 'Run the precise lint+test subset for a diff/file list (NDJSON, agent-facing)')]
final class AiVerifyCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:verify');
    }

    protected function configure(): void
    {
        $this
            ->addOption('files', null, InputOption::VALUE_REQUIRED, 'Comma-separated repo-relative paths to verify')
            ->addOption('git-ref', null, InputOption::VALUE_REQUIRED, 'Compare working tree against this git ref (e.g. HEAD~1, origin/main)')
            ->addOption('diff-stdin', null, InputOption::VALUE_NONE, 'Read newline-separated paths from stdin (output of `git diff --name-only`)')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Verification scope: minimal, standard, broad', VerificationPlan::SCOPE_STANDARD)
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a verify_result event to this ai:trace id (falls back to $SEMITEXA_AI_TRACE_ID)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope instead of NDJSON');
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
            static fn(array $entry) => $classifier->classify($entry['path'], $entry['status']),
            $paths,
        );
        /** @var list<ChangedFile> $changed */

        $planner = new VerificationPlanner($projectRoot, $classifier);
        $plan = $planner->plan($changed, $scope);

        $app = $this->getApplication();
        if ($app === null) {
            $this->emitError($output, 'Application not available — cannot dispatch lint commands', $jsonMode);
            return self::FAILURE;
        }
        $executor = new VerificationExecutor($app, $projectRoot);
        $results = $executor->execute($plan);

        $verdict = $this->verdict($results);
        $exit = $verdict === VerificationResult::STATUS_FAIL ? self::FAILURE : self::SUCCESS;

        $envelope = $this->buildEnvelope($plan, $results, $verdict);
        if ($jsonMode) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
        } else {
            $this->emitNdjson($output, $plan, $results, $verdict);
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
        $appender = new TraceAutoAppender(new TraceStore($this->getProjectRoot()));
        $appender->appendIfActive($input, $output, TraceEventKind::VERIFY_RESULT, $summary, $envelope);
    }

    /**
     * @return list<array{path: string, status: string}>
     */
    private function collectPaths(InputInterface $input): array
    {
        $sources = [];
        if (($files = $input->getOption('files')) !== null && $files !== '') {
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
        return $this->dedupe($sources);
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
     * @return list<array{path: string, status: string}>
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
                // For R entries, `git diff --name-status` emits OLD<TAB>NEW; take the new path.
                $path = $parts[count($parts) - 1];
                $out[] = ['path' => $path, 'status' => $status];
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
    private function buildEnvelope(VerificationPlan $plan, array $results, string $verdict): array
    {
        return [
            'artifact'        => 'semitexa-dev.verify-report/v1',
            'generated_at'    => date('c'),
            'requested_scope' => $plan->scope,
            'effective_scope' => $plan->effectiveScope,
            'expansions'      => $plan->expansions,
            'changed_files'   => array_map(
                static fn(ChangedFile $f) => ['path' => $f->path, 'kind' => $f->kind, 'status' => $f->status],
                $plan->changedFiles,
            ),
            'targets'         => array_map(fn($t) => $this->serializeTarget($t), $plan->targets),
            'results'         => array_map(fn($r) => $this->serializeResult($r), $results),
            'verdict'         => $verdict,
            'counts'          => $this->countByStatus($results),
        ];
    }

    /**
     * @param list<VerificationResult> $results
     */
    private function emitNdjson(OutputInterface $output, VerificationPlan $plan, array $results, string $verdict): void
    {
        $output->writeln(json_encode([
            'kind'            => 'summary',
            'requested_scope' => $plan->scope,
            'effective_scope' => $plan->effectiveScope,
            'changed_files'   => count($plan->changedFiles),
            'targets'         => count($plan->targets),
        ], JSON_UNESCAPED_SLASHES));

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
        }

        $output->writeln(json_encode([
            'kind'    => 'verdict',
            'verdict' => $verdict,
            'counts'  => $this->countByStatus($results),
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTarget(\Semitexa\Dev\Ai\Verify\VerificationTarget $target): array
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
        return [
            'id'        => $result->target->id,
            'type'      => $result->target->type,
            'status'    => $result->status,
            'exit_code' => $result->exitCode,
            'signal'    => $result->signal,
        ];
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
