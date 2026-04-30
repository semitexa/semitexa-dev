<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

use Semitexa\Dev\Ai\Verify\Structure\ModuleStructureTargetResolver;

/**
 * Deterministic planner: changed file list + requested scope → VerificationPlan.
 *
 * Scope contract:
 *   - `minimal`  syntax only (`php -l` per changed .php file)
 *   - `standard` syntax + kind-mapped lints + related test files
 *   - `broad`    all lints + syntax + related tests
 *
 * Auto-expansion (standard → broad):
 *   - any changed file is under `/Domain/Contract/` (contracts ripple)
 *   - total changed-file count ≥ `AUTO_EXPAND_FILE_THRESHOLD`
 *
 * Deliberately conservative: expansions are listed in the plan so the NDJSON
 * report explains *why* the scope widened. Callers always see what they asked
 * for plus any bumps.
 */
final class VerificationPlanner
{
    private const AUTO_EXPAND_FILE_THRESHOLD = 15;

    /**
     * Kind → list<command-name>. The `@all` marker means "every lint" — used
     * only when scope is effectively `broad`.
     */
    private const KIND_LINT_MAP = [
        ChangedFile::KIND_HANDLER  => ['lint:handlers', 'lint:di'],
        ChangedFile::KIND_LISTENER => ['lint:di', 'lint:scoping'],
        ChangedFile::KIND_PAYLOAD  => ['lint:responses', 'lint:di'],
        ChangedFile::KIND_RESOURCE => ['lint:responses'],
        ChangedFile::KIND_SERVICE  => ['lint:di', 'lint:scoping'],
        ChangedFile::KIND_CONTRACT => ['lint:di'],
        ChangedFile::KIND_TEMPLATE => ['lint:templates'],
    ];

    private const ALL_LINTS = [
        'lint:handlers',
        'lint:di',
        'lint:scoping',
        'lint:responses',
        'lint:templates',
    ];

    private readonly ModuleStructureTargetResolver $targetResolver;

    public function __construct(
        private readonly string $projectRoot,
        private readonly ChangedFileClassifier $classifier = new ChangedFileClassifier(),
        ?ModuleStructureTargetResolver $targetResolver = null,
    ) {
        $this->targetResolver = $targetResolver ?? new ModuleStructureTargetResolver($projectRoot);
    }

    /**
     * @param list<ChangedFile> $changedFiles
     */
    public function plan(array $changedFiles, string $requestedScope): VerificationPlan
    {
        [$effectiveScope, $expansions] = $this->resolveEffectiveScope($requestedScope, $changedFiles);

        $lintsByCommand = [];
        $syntaxTargets = [];
        $phpunitTargets = [];

        foreach ($changedFiles as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED) {
                continue;
            }

            if (str_ends_with($file->path, '.php')) {
                $syntaxTargets[$file->path] = new VerificationTarget(
                    type: VerificationTarget::TYPE_SYNTAX,
                    id: "syntax:{$file->path}",
                    reason: 'php syntax check on changed file',
                    triggeredBy: [$file->path],
                    filePath: $file->path,
                );
            }

            if ($effectiveScope !== VerificationPlan::SCOPE_MINIMAL) {
                $this->collectLintsForFile($file, $effectiveScope, $lintsByCommand);
                $this->collectPhpunitForFile($file, $phpunitTargets);
            }
        }

        if ($effectiveScope === VerificationPlan::SCOPE_BROAD) {
            foreach (self::ALL_LINTS as $lint) {
                $lintsByCommand[$lint] ??= [
                    'triggeredBy' => [],
                    'reason'      => 'broad scope — every lint is required',
                ];
            }
        }

        $targets = array_values($syntaxTargets);
        foreach ($lintsByCommand as $commandName => $meta) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_LINT,
                id: "lint:{$commandName}",
                reason: $meta['reason'],
                triggeredBy: array_values(array_unique($meta['triggeredBy'])),
                commandName: $commandName,
            );
        }
        foreach ($phpunitTargets as $target) {
            $targets[] = $target;
        }
        if ($effectiveScope !== VerificationPlan::SCOPE_MINIMAL) {
            foreach ($this->moduleStructureTargets($changedFiles) as $target) {
                $targets[] = $target;
            }
            $phpstanTarget = $this->phpstanDiTarget($changedFiles);
            if ($phpstanTarget !== null) {
                $targets[] = $phpstanTarget;
            }
        }

        return new VerificationPlan(
            scope: $requestedScope,
            effectiveScope: $effectiveScope,
            changedFiles: $changedFiles,
            targets: $targets,
            expansions: $expansions,
        );
    }

    /**
     * Schedule one `module_structure` target per affected module. Runs at every
     * scope (including `minimal`): structure violations are cheap to detect and
     * are the worst kind of drift to leave for later, so they are non-optional.
     *
     * @param list<ChangedFile> $files
     * @return list<VerificationTarget>
     */
    private function moduleStructureTargets(array $files): array
    {
        $modules = $this->targetResolver->resolve($files);
        $targets = [];
        foreach ($modules as $module) {
            $triggeredBy = [];
            foreach ($files as $f) {
                if (str_starts_with('/' . ltrim($f->path, '/'), '/' . $module->relativePath . '/')
                    || ltrim($f->path, '/') === $module->relativePath
                ) {
                    $triggeredBy[] = $f->path;
                }
            }
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_MODULE_STRUCTURE,
                id: "module_structure:{$module->relativePath}",
                reason: "validate module structure of {$module->relativePath} against MODULE_STRUCTURE.md",
                triggeredBy: array_values(array_unique($triggeredBy)),
                filePath: $module->relativePath,
            );
        }
        return $targets;
    }

    /**
     * Schedule a single `phpstan_di` target covering every eligible changed
     * PHP file. PHPStan owns the DI rules — `ai:verify` is the AI-facing
     * orchestrator that asks PHPStan about *just these files* and reports
     * back. Skips deleted files (no source to analyse), test classes and
     * fixtures (DI rules apply to production code only), templates, and
     * non-PHP entries.
     *
     * Batched (one PHPStan invocation per verify run) instead of per-file
     * because PHPStan bootstrap + autoloader is the dominant cost — twenty
     * `phpstan analyse FileN.php` invocations would multiply that by twenty
     * for no extra coverage.
     *
     * @param list<ChangedFile> $files
     */
    private function phpstanDiTarget(array $files): ?VerificationTarget
    {
        $eligible = [];
        foreach ($files as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED) {
                continue;
            }
            if (!str_ends_with($file->path, '.php')) {
                continue;
            }
            if ($file->kind === ChangedFile::KIND_TEST
                || $file->kind === ChangedFile::KIND_TEST_FIXTURE
                || $file->kind === ChangedFile::KIND_TEMPLATE
                || $file->kind === ChangedFile::KIND_NON_PHP
            ) {
                continue;
            }
            $eligible[] = $file->path;
        }

        if ($eligible === []) {
            return null;
        }

        return new VerificationTarget(
            type: VerificationTarget::TYPE_PHPSTAN_DI,
            id: 'phpstan_di:' . count($eligible) . '-files',
            reason: 'validate Semitexa attribute-only DI via PHPStan rules (semitexa.injectionViaConstructor + siblings)',
            triggeredBy: $eligible,
            filePath: null,
            testFilter: null,
        );
    }

    /**
     * @param list<ChangedFile> $files
     * @return array{0: string, 1: list<string>}
     */
    private function resolveEffectiveScope(string $requested, array $files): array
    {
        $scope = in_array($requested, [
            VerificationPlan::SCOPE_MINIMAL,
            VerificationPlan::SCOPE_STANDARD,
            VerificationPlan::SCOPE_BROAD,
        ], true) ? $requested : VerificationPlan::SCOPE_STANDARD;

        $expansions = [];
        if ($scope === VerificationPlan::SCOPE_STANDARD) {
            foreach ($files as $f) {
                if ($f->kind === ChangedFile::KIND_CONTRACT) {
                    $scope = VerificationPlan::SCOPE_BROAD;
                    $expansions[] = "contract changed ({$f->path}) — contracts ripple, expanding to broad scope";
                    break;
                }
            }
        }
        if ($scope === VerificationPlan::SCOPE_STANDARD && count($files) >= self::AUTO_EXPAND_FILE_THRESHOLD) {
            $scope = VerificationPlan::SCOPE_BROAD;
            $expansions[] = sprintf(
                '%d files changed (≥ %d threshold) — expanding to broad scope',
                count($files),
                self::AUTO_EXPAND_FILE_THRESHOLD,
            );
        }

        return [$scope, $expansions];
    }

    /**
     * @param array<string, array{triggeredBy: list<string>, reason: string}> $lintsByCommand
     */
    private function collectLintsForFile(
        ChangedFile $file,
        string $effectiveScope,
        array &$lintsByCommand,
    ): void {
        $lints = self::KIND_LINT_MAP[$file->kind] ?? [];
        foreach ($lints as $lint) {
            if (!isset($lintsByCommand[$lint])) {
                $lintsByCommand[$lint] = [
                    'triggeredBy' => [],
                    'reason'      => "{$file->kind} change requires {$lint}",
                ];
            }
            $lintsByCommand[$lint]['triggeredBy'][] = $file->path;
        }
    }

    /**
     * @param array<string, VerificationTarget> $phpunitTargets
     */
    private function collectPhpunitForFile(ChangedFile $file, array &$phpunitTargets): void
    {
        if ($file->kind === ChangedFile::KIND_TEST) {
            $className = $this->testClassFromPath($file->path);
            if ($className === null) {
                return;
            }
            $phpunitTargets[$className] ??= new VerificationTarget(
                type: VerificationTarget::TYPE_PHPUNIT,
                id: "phpunit:{$className}",
                reason: 'test file changed — running it directly',
                triggeredBy: [$file->path],
                filePath: $file->path,
                testFilter: $className,
            );
            return;
        }

        if ($file->kind === ChangedFile::KIND_NON_PHP || $file->kind === ChangedFile::KIND_TEMPLATE) {
            return;
        }

        if ($file->kind === ChangedFile::KIND_TEST_FIXTURE) {
            $this->scheduleEnclosingTestSuite($file, $phpunitTargets);
            return;
        }

        foreach ($this->findMatchingTests($file->path) as $testClass => $testPath) {
            if (isset($phpunitTargets[$testClass])) {
                $phpunitTargets[$testClass] = new VerificationTarget(
                    type: VerificationTarget::TYPE_PHPUNIT,
                    id: "phpunit:{$testClass}",
                    reason: $phpunitTargets[$testClass]->reason,
                    triggeredBy: array_values(array_unique([...$phpunitTargets[$testClass]->triggeredBy, $file->path])),
                    filePath: $phpunitTargets[$testClass]->filePath ?? $testPath,
                    testFilter: $testClass,
                );
                continue;
            }
            $phpunitTargets[$testClass] = new VerificationTarget(
                type: VerificationTarget::TYPE_PHPUNIT,
                id: "phpunit:{$testClass}",
                reason: "matching test found for {$this->classNameFromPath($file->path)}",
                triggeredBy: [$file->path],
                filePath: $testPath,
                testFilter: $testClass,
            );
        }
    }

    /**
     * Phase 6f.5: a changed test fixture / stub / helper is not a
     * PHPUnit test class on its own — invoking phpunit on the file
     * directly fails with "does not extend TestCase". Instead, find
     * the smallest enclosing directory that contains real `*Test.php`
     * files and schedule a single directory-scoped phpunit target.
     * Running phpunit on the directory loads every sibling Test.php
     * in one process, which is both more efficient than N per-file
     * invocations and resilient to cross-file helper class
     * declarations the existing test corpus relies on.
     *
     * @param array<string, VerificationTarget> $phpunitTargets
     */
    private function scheduleEnclosingTestSuite(ChangedFile $file, array &$phpunitTargets): void
    {
        $enclosingDirRel = $this->findEnclosingTestDir($file->path);
        if ($enclosingDirRel === null) {
            return;
        }

        $key = "phpunit-dir:{$enclosingDirRel}";
        if (isset($phpunitTargets[$key])) {
            $phpunitTargets[$key] = new VerificationTarget(
                type: VerificationTarget::TYPE_PHPUNIT,
                id: $phpunitTargets[$key]->id,
                reason: $phpunitTargets[$key]->reason,
                triggeredBy: array_values(array_unique([...$phpunitTargets[$key]->triggeredBy, $file->path])),
                filePath: $enclosingDirRel,
                testFilter: null,
            );
            return;
        }

        $phpunitTargets[$key] = new VerificationTarget(
            type: VerificationTarget::TYPE_PHPUNIT,
            id: "phpunit:{$enclosingDirRel}",
            reason: "test fixture changed — running enclosing test suite ({$enclosingDirRel})",
            triggeredBy: [$file->path],
            filePath: $enclosingDirRel,
            testFilter: null,
        );
    }

    /**
     * Walk up from the fixture's directory until an ancestor contains
     * at least one `*Test.php` file (recursively). Return that
     * ancestor's repo-relative path. Walking stops at the project
     * root or at a non-existent ancestor; returns `null` if the
     * fixture has no enclosing test sub-tree.
     */
    private function findEnclosingTestDir(string $changedRelPath): ?string
    {
        $relDir = dirname($changedRelPath);
        while ($relDir !== '' && $relDir !== '.' && $relDir !== '/') {
            $abs = $this->projectRoot . '/' . ltrim($relDir, '/');
            if (is_dir($abs) && $this->dirContainsTests($abs)) {
                return $relDir;
            }
            $relDir = dirname($relDir);
        }
        return null;
    }

    private function dirContainsTests(string $absDir): bool
    {
        $iter = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absDir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iter as $entry) {
            if (!$entry->isFile()) {
                continue;
            }
            if (str_ends_with((string) $entry->getRealPath(), 'Test.php')) {
                return true;
            }
        }
        return false;
    }

    private function testClassFromPath(string $relPath): ?string
    {
        if (!str_ends_with($relPath, '.php')) {
            return null;
        }
        $base = basename($relPath, '.php');
        return $base === '' ? null : $base;
    }

    private function classNameFromPath(string $relPath): string
    {
        return basename($relPath, '.php');
    }

    /**
     * @return array<string, string> testClassName => relative test path
     */
    private function findMatchingTests(string $changedRelPath): array
    {
        $base = $this->classNameFromPath($changedRelPath);
        if ($base === '') {
            return [];
        }
        $results = [];
        foreach ($this->candidateTestRoots() as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iter as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $entryPath = (string) $entry->getRealPath();
                if (!str_ends_with($entryPath, 'Test.php')) {
                    continue;
                }
                if (str_contains(basename($entryPath), $base)) {
                    $className = basename($entryPath, '.php');
                    $rel = ltrim(str_replace($this->projectRoot, '', $entryPath), '/');
                    $results[$className] = $rel;
                }
            }
        }
        return $results;
    }

    /**
     * @return list<string>
     */
    private function candidateTestRoots(): array
    {
        $roots = [$this->projectRoot . '/tests'];
        $packagesDir = $this->projectRoot . '/packages';
        if (is_dir($packagesDir)) {
            foreach (scandir($packagesDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $maybe = $packagesDir . '/' . $entry . '/tests';
                if (is_dir($maybe)) {
                    $roots[] = $maybe;
                }
            }
        }
        return $roots;
    }
}
