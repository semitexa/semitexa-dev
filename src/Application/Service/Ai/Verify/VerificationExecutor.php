<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunner;
use Semitexa\Dev\Application\Service\Ai\Verify\Phpstan\PhpstanRunResult;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureValidator;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;
use Semitexa\Dev\Application\Service\Capability\FrameworkCapabilityCatalog;
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
                VerificationTarget::TYPE_CAPABILITY_INDEX => $this->runCapabilityIndex($target),
                VerificationTarget::TYPE_CAPABILITY_COVERAGE => $this->runCapabilityCoverage($target),
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

    /**
     * Compare the shipped capability index against what the packages declare.
     *
     * Skipped rather than failed outside the monorepo: a checkout that cannot
     * see every package cannot regenerate the file, so failing there would
     * report a defect nobody in that project can fix. The planner already
     * scopes this to `packages/`, but the executor states the same condition
     * because a skip with a reason is what makes an unrun gate visible.
     */
    private function runCapabilityIndex(VerificationTarget $target): VerificationResult
    {
        if (!CapabilityIndex::isFullView($this->projectRoot)) {
            return $this->skipped(
                $target,
                'capability_index: not the monorepo (fewer than '
                . CapabilityIndex::MIN_PACKAGES_FOR_A_FULL_VIEW
                . ' Semitexa packages on disk); the index can only be built where every package is present',
            );
        }

        try {
            $discovery = new ClassDiscovery();
            // The same reading the builder uses. Comparing an on-disk index
            // against an installed-only catalog would report drift on every run
            // for the packages this checkout does not require, and a gate that
            // fails on nothing is a gate somebody switches off.
            $live = (new FrameworkCapabilityCatalog($discovery))->everythingOnDisk($this->projectRoot);
            $shipped = CapabilityIndex::read(CapabilityIndex::path($this->projectRoot));
        } catch (\Throwable $e) {
            return $this->skipped($target, 'capability_index discovery failed: ' . $this->compress($e->getMessage()));
        }

        if (CapabilityIndex::isInSync($live, $shipped)) {
            return new VerificationResult(
                target:   $target,
                status:   VerificationResult::STATUS_PASS,
                exitCode: 0,
                signal:   'capability_index → in sync (' . count($live) . ' capabilities)',
            );
        }

        // Name the command that fixes it. A gate that only says "stale" costs
        // the reader a search for the one command that regenerates the file.
        $shippedCount = is_array($shipped['capabilities'] ?? null) ? count($shipped['capabilities']) : 0;

        return new VerificationResult(
            target:      $target,
            status:      VerificationResult::STATUS_FAIL,
            exitCode:    1,
            signal:      sprintf(
                'capability_index → stale (shipped %d, declared %d); run bin/semitexa dev:capability-index:build',
                $shippedCount,
                count($live),
            ),
            diagnostics: [[
                'code' => 'CAPABILITY_INDEX_STALE',
                'path' => CapabilityIndex::RELATIVE_PATH,
                'message' => 'The shipped index no longer matches the #[Capability] declarations on disk. '
                    . 'Consumer projects read it to learn about packages they have not installed, so a stale '
                    . 'index advertises the wrong set. Regenerate with: bin/semitexa dev:capability-index:build',
            ]],
        );
    }

    /**
     * Require every Composer package to say what it offers.
     *
     * The freshness gate next to this one keeps the shipped index matching the
     * declarations that exist. Neither it nor anything else noticed a package
     * that declared nothing at all — which is how eleven of them came to be
     * silent, none by a decision anyone could point to afterwards. A convention
     * with no gate is a convention that survives exactly as long as the memory
     * of the session that introduced it.
     *
     * Filesystem-only, so it costs a stat per package and can run wherever the
     * monorepo is visible. Skipped elsewhere for the same reason as the
     * freshness gate: a consumer has no `packages/` tree to be right or wrong
     * about.
     */
    private function runCapabilityCoverage(VerificationTarget $target): VerificationResult
    {
        if (!CapabilityIndex::isFullView($this->projectRoot)) {
            return $this->skipped(
                $target,
                'capability_coverage: not the monorepo (fewer than '
                . CapabilityIndex::MIN_PACKAGES_FOR_A_FULL_VIEW
                . ' Semitexa packages on disk); package coverage can only be judged where every package is present',
            );
        }

        // Degrades like the freshness gate next to it. An unreadable manifest or
        // a permission error here is a fact about the working copy, not about
        // whether the packages declare — failing the whole verify run over it
        // would report a defect in the wrong place.
        try {
            $missing = CapabilityIndex::packagesWithoutDeclaration($this->projectRoot);
        } catch (\Throwable $e) {
            return $this->skipped($target, 'capability_coverage scan failed: ' . $this->compress($e->getMessage()));
        }

        if ($missing === []) {
            return new VerificationResult(
                target:   $target,
                status:   VerificationResult::STATUS_PASS,
                exitCode: 0,
                signal:   'capability_coverage → every package declares what it offers',
            );
        }

        return new VerificationResult(
            target:      $target,
            status:      VerificationResult::STATUS_FAIL,
            exitCode:    1,
            signal:      sprintf(
                'capability_coverage → %d package(s) declare nothing: %s',
                count($missing),
                implode(', ', array_column($missing, 'name')),
            ),
            // One diagnostic per package: the reader fixes them one at a time,
            // and a single line naming five packages reads as one problem.
            diagnostics: array_map(
                static fn (array $package): array => [
                    'code' => 'CAPABILITY_DECLARATION_MISSING',
                    'path' => $package['path'],
                    'message' => sprintf(
                        '%s ships no %s, so nothing tells a project that has not installed it that it exists. '
                        . 'Add the class with one #[Capability] naming what someone would otherwise build by hand '
                        . '(see packages/semitexa-os/src/Capabilities.php), then run '
                        . 'bin/semitexa dev:capability-index:build. If the package genuinely has nothing missable '
                        . 'to offer, add it to CapabilityIndex::DECLARATION_EXEMPT with the reason.',
                        $package['name'],
                        CapabilityIndex::DECLARATION_PATH,
                    ),
                ],
                $missing,
            ),
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
