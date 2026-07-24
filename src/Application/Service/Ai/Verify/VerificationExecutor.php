<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunResult;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs every {@see VerificationTarget} in a {@see VerificationPlan} and
 * collects per-target outcomes. Three dispatch paths:
 *
 *   - lint    → Application::find()->run() with BufferedOutput
 *               (matches {@see \Semitexa\Dev\Application\Service\Generation\Verifier\PostWriteLinter})
 *   - syntax  → `php -l <file>`
 *   - phpunit → `vendor/bin/phpunit --filter <class>`
 *
 * The executor never throws on target failure — failures show up in the
 * returned {@see VerificationResult} list so the command can emit them as
 * NDJSON. Anything we genuinely can't run (missing command, missing phpunit
 * binary) is reported as `skipped` with a one-line reason.
 */
final class VerificationExecutor
{
    private readonly ModuleStructureSpecLoader $specLoader;

    private readonly PhpstanRunner $phpstanRunner;

    public function __construct(
        private readonly Application $application,
        private readonly string $projectRoot,
        private readonly ProcessRunner $processRunner = new ShellProcessRunner(),
        private readonly ?string $phpBinary = null,
        private readonly ?string $phpunitBinary = null,
        ?ModuleStructureSpecLoader $specLoader = null,
        ?PhpstanRunner $phpstanRunner = null,
    ) {
        $this->specLoader = $specLoader ?? new ModuleStructureSpecLoader($projectRoot);
        $this->phpstanRunner = $phpstanRunner ?? new PhpstanRunner($projectRoot, $this->processRunner);
    }

    /**
     * @return list<VerificationResult>
     */
    public function execute(VerificationPlan $plan): array
    {
        $results = [];
        foreach ($plan->targets as $target) {
            $results[] = match ($target->type) {
                VerificationTarget::TYPE_LINT             => $this->runLint($target),
                VerificationTarget::TYPE_SYNTAX           => $this->runSyntax($target),
                VerificationTarget::TYPE_PHPUNIT          => $this->runPhpunit($target),
                VerificationTarget::TYPE_MODULE_STRUCTURE => $this->runModuleStructure($target),
                VerificationTarget::TYPE_PHPSTAN_DI       => $this->runPhpstanDi($target),
                VerificationTarget::TYPE_LIVE_TENANCY     => $this->runLiveTenancy($target),
                default                                   => new VerificationResult(
                    target:   $target,
                    status:   VerificationResult::STATUS_SKIPPED,
                    exitCode: 0,
                    signal:   "unknown target type: {$target->type}",
                ),
            };
        }
        return $results;
    }

    private function runLint(VerificationTarget $target): VerificationResult
    {
        $commandName = $target->commandName;
        if ($commandName === null) {
            return $this->skipped($target, 'lint target missing commandName');
        }
        try {
            $command = $this->application->find($commandName);
        } catch (CommandNotFoundException) {
            // A planned gate that does not exist is a defect in the plan, not a
            // reason to pass. Reporting it as skipped is how five lints named
            // with a stale prefix went unrun while ai:verify kept saying pass.
            return $this->failed($target, "command {$commandName} is not registered");
        }

        $buffer = new BufferedOutput();
        $input = new ArrayInput(['command' => $commandName]);
        $input->setInteractive(false);

        try {
            $exit = $command->run($input, $buffer);
        } catch (\Throwable $e) {
            // Same reasoning: a gate that blew up did not clear the change.
            return $this->failed(
                $target,
                "{$commandName} threw " . $e::class . ': ' . $this->compress($e->getMessage()),
            );
        }

        return new VerificationResult(
            target:   $target,
            status:   $exit === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode: $exit,
            signal:   $this->lastSignalLine($buffer->fetch()),
        );
    }

    private function runSyntax(VerificationTarget $target): VerificationResult
    {
        $rel = $target->filePath;
        if ($rel === null) {
            return $this->skipped($target, 'syntax target missing filePath');
        }
        $abs = $this->projectRoot . '/' . ltrim($rel, '/');
        if (!is_file($abs)) {
            return $this->skipped($target, "file no longer exists: {$rel}");
        }
        $php = $this->phpBinary ?? (PHP_BINARY ?: 'php');
        $r = $this->processRunner->run([$php, '-l', $abs], $this->projectRoot);

        return new VerificationResult(
            target:   $target,
            status:   $r['exit'] === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode: $r['exit'],
            signal:   $this->lastSignalLine($r['output']),
        );
    }

    private function runPhpunit(VerificationTarget $target): VerificationResult
    {
        $filter = $target->testFilter;
        $rel    = $target->filePath;

        // Phase 6f.5: a phpunit target may be either:
        //   - filtered: a single test class via `--filter Class file.php`,
        //     or
        //   - suite-scoped: a test directory via `phpunit <dir>` (no
        //     filter), used by the fixture heuristic to run an entire
        //     enclosing test sub-tree in one process. Suite-scoped
        //     mode bypasses the cross-file-dependency fragility of
        //     `--filter Class file.php` (helper classes declared in
        //     sibling test files load correctly because phpunit walks
        //     the directory).
        if ($filter === null && $rel === null) {
            return $this->skipped($target, 'phpunit target missing both testFilter and filePath');
        }

        $binary = $this->phpunitBinary ?? $this->discoverPhpunitBinary();
        if ($binary === null) {
            return $this->skipped($target, 'phpunit binary not found (looked for vendor/bin/phpunit)');
        }

        $abs = $rel !== null ? $this->projectRoot . '/' . ltrim($rel, '/') : null;
        $isDir = $abs !== null && is_dir($abs);

        $command = [$binary];
        if ($filter !== null) {
            $command[] = '--filter';
            $command[] = $filter;
        }
        if ($abs !== null && (is_file($abs) || $isDir)) {
            $command[] = $rel;
        } elseif ($filter === null) {
            // Suite-scoped target lost its directory between plan and
            // execute (e.g. file deleted on disk).
            return $this->skipped($target, "phpunit target directory no longer exists: {$rel}");
        }

        $r = $this->processRunner->run($command, $this->projectRoot);

        // phpunit exits 0 when filter+suite resolve to zero tests. Treat that as
        // a discovery failure rather than success — otherwise installer/scaffold
        // changes can silently report "pass" while the suite would actually fail.
        $noTestsExecuted = str_contains($r['output'], 'No tests executed!');

        $describe = $filter !== null ? "--filter {$filter}" : "<{$rel}>";
        $signal = $this->lastSignalLine($r['output']);
        if ($signal === '') {
            $signal = "phpunit {$describe} → exit {$r['exit']}";
        }
        if ($noTestsExecuted) {
            $signal = "phpunit {$describe} matched no tests (discovery gap, not a pass)";
        }

        $status = ($r['exit'] === 0 && ! $noTestsExecuted)
            ? VerificationResult::STATUS_PASS
            : VerificationResult::STATUS_FAIL;

        return new VerificationResult(
            target:   $target,
            status:   $status,
            exitCode: $r['exit'],
            signal:   $signal,
        );
    }

    private function runModuleStructure(VerificationTarget $target): VerificationResult
    {
        $rel = $target->filePath;
        if ($rel === null) {
            return $this->skipped($target, 'module_structure target missing filePath (module root)');
        }
        $abs = $this->projectRoot . '/' . ltrim($rel, '/');
        if (!is_dir($abs)) {
            return $this->skipped($target, "module directory no longer exists: {$rel}");
        }

        $module = $this->reconstructDetectedModule($rel);
        if ($module === null) {
            return $this->skipped($target, "module path not under src/modules/ or packages/: {$rel}");
        }

        try {
            $spec = $this->specLoader->load();
        } catch (\Throwable $e) {
            return $this->skipped($target, 'module_structure spec load failed: ' . $this->compress($e->getMessage()));
        }

        $validator = new ModuleStructureValidator($this->projectRoot, $spec);
        $violations = $validator->validate($module);

        $diagnostics = array_map(
            static fn(ModuleStructureViolation $v) => $v->toArray(),
            $violations,
        );

        $errorCount = count($violations);
        if ($errorCount === 0) {
            $signal = "module_structure {$rel} → 0 violations";
        } else {
            $first = $violations[0];
            $signal = "module_structure {$rel} → {$errorCount} error(s); first: {$first->code} {$first->path}";
        }

        return new VerificationResult(
            target:      $target,
            status:      $errorCount === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode:    $errorCount === 0 ? 0 : 1,
            signal:      $signal,
            diagnostics: $diagnostics,
        );
    }

    private function runLiveTenancy(VerificationTarget $target): VerificationResult
    {
        try {
            $validator = new \Semitexa\Dev\Application\Service\Ai\Verify\Tenancy\LiveResourceTenancyValidator();
            $violations = $validator->validateProject(new \Semitexa\Core\Discovery\ClassDiscovery());
        } catch (\Throwable $e) {
            return $this->skipped($target, 'live_tenancy discovery failed: ' . $this->compress($e->getMessage()));
        }

        $diagnostics = array_map(
            static fn(\Semitexa\Dev\Application\Service\Ai\Verify\Tenancy\LiveTenancyViolation $v) => $v->toArray(),
            $violations,
        );

        $errorCount = count($violations);
        $signal = $errorCount === 0
            ? 'live_tenancy → 0 violations'
            : "live_tenancy → {$errorCount} violation(s); first: {$violations[0]->code} {$violations[0]->scopeKey}";

        return new VerificationResult(
            target:      $target,
            status:      $errorCount === 0 ? VerificationResult::STATUS_PASS : VerificationResult::STATUS_FAIL,
            exitCode:    $errorCount === 0 ? 0 : 1,
            signal:      $signal,
            diagnostics: $diagnostics,
        );
    }

    private function runPhpstanDi(VerificationTarget $target): VerificationResult
    {
        $files = $target->triggeredBy;
        if ($files === []) {
            return $this->skipped($target, 'phpstan_di target has no files');
        }

        $existing = [];
        foreach ($files as $rel) {
            if (is_file($this->projectRoot . '/' . ltrim($rel, '/'))) {
                $existing[] = $rel;
            }
        }
        if ($existing === []) {
            return $this->skipped($target, 'phpstan_di: none of the changed files exist on disk');
        }

        $result = $this->phpstanRunner->run($existing);

        return new VerificationResult(
            target:      $target,
            status:      $this->mapPhpstanStatus($result->status),
            exitCode:    $result->status === PhpstanRunResult::STATUS_FAIL ? 1 : 0,
            signal:      $result->rawSignal,
            diagnostics: $result->diagnostics,
        );
    }

    private function mapPhpstanStatus(string $phpstanStatus): string
    {
        return match ($phpstanStatus) {
            PhpstanRunResult::STATUS_PASS    => VerificationResult::STATUS_PASS,
            PhpstanRunResult::STATUS_FAIL    => VerificationResult::STATUS_FAIL,
            PhpstanRunResult::STATUS_SKIPPED => VerificationResult::STATUS_SKIPPED,
            default                          => VerificationResult::STATUS_SKIPPED,
        };
    }

    private function reconstructDetectedModule(string $rel): ?DetectedModule
    {
        $parts = explode('/', trim($rel, '/'));
        if (count($parts) === 3 && $parts[0] === 'src' && $parts[1] === 'modules') {
            return new DetectedModule(
                name: $parts[2],
                relativePath: $rel,
                kind: DetectedModule::KIND_APPLICATION,
            );
        }
        if (count($parts) === 2 && $parts[0] === 'packages' && str_starts_with($parts[1], 'semitexa-')) {
            return new DetectedModule(
                name: substr($parts[1], strlen('semitexa-')),
                relativePath: $rel,
                kind: DetectedModule::KIND_PACKAGE,
            );
        }
        return null;
    }

    private function discoverPhpunitBinary(): ?string
    {
        foreach (['/vendor/bin/phpunit', '/bin/phpunit'] as $candidate) {
            $abs = $this->projectRoot . $candidate;
            if (is_file($abs) && is_executable($abs)) {
                return $abs;
            }
        }
        return null;
    }

    private function skipped(VerificationTarget $target, string $reason): VerificationResult
    {
        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_SKIPPED,
            exitCode: 0,
            signal:   $reason,
        );
    }

    /**
     * A gate that could not be run at all. Distinct from skipped: skipped means
     * the target did not apply, failed means it applied and did not clear.
     */
    private function failed(VerificationTarget $target, string $reason): VerificationResult
    {
        return new VerificationResult(
            target:   $target,
            status:   VerificationResult::STATUS_FAIL,
            exitCode: 1,
            signal:   $reason,
        );
    }

    private function lastSignalLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim(preg_replace('/\[[a-zA-Z]+\]/', '', $lines[$i]) ?? '');
            if ($line === '') {
                continue;
            }
            return $this->compress($line);
        }
        return '';
    }

    private function compress(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;
        return strlen($value) > 240 ? substr($value, 0, 237) . '...' : $value;
    }
}
