<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureTargetResolver;
use Semitexa\Dev\Application\Service\Capability\CapabilityIndex;

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
        // lint:deferred-twig belongs here because a deferred slot template is rendered
        // TWICE - by Twig on the server and by semitexa-twig.js on the client - and the
        // client subset has no functions, no filters beyond |raw and no ternary. Anything
        // outside it renders as an EMPTY STRING with no error, so the divergence is
        // invisible until someone notices missing text. The command already existed and
        // was wired into no gate at all; it was red on a real template when connected.
        ChangedFile::KIND_TEMPLATE => ['lint:templates', 'lint:mechanisms', 'lint:deferred-twig'],
        // Client JavaScript is where a framework mechanism gets hand-rolled:
        // a region fetched and injected instead of declared deferred.
        ChangedFile::KIND_CLIENT_SCRIPT => ['lint:mechanisms'],
    ];

    private const ALL_LINTS = [
        'lint:handlers',
        'lint:di',
        'lint:scoping',
        'lint:responses',
        'lint:templates',
        'lint:mechanisms',
        'lint:deferred-twig',
    ];

    private readonly ModuleStructureTargetResolver $targetResolver;

    private readonly ContractMoveResolver $contractMoveResolver;

    public function __construct(
        private readonly string $projectRoot,
        private readonly ChangedFileClassifier $classifier = new ChangedFileClassifier(),
        ?ModuleStructureTargetResolver $targetResolver = null,
        ?ContractMoveResolver $contractMoveResolver = null,
    ) {
        $this->targetResolver = $targetResolver ?? new ModuleStructureTargetResolver($projectRoot);
        $this->contractMoveResolver = $contractMoveResolver ?? new ContractMoveResolver($projectRoot);
    }

    /**
     * @param list<ChangedFile> $changedFiles
     */
    public function plan(array $changedFiles, string $requestedScope, bool $isRepoWide = false): VerificationPlan
    {
        [$effectiveScope, $expansions] = $this->resolveEffectiveScope($requestedScope, $changedFiles);
        $contractMoves = $this->contractMoveResolver->resolve($changedFiles);
        foreach ($contractMoves as $move) {
            $expansions[] = $move->reason();
        }

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

        // Module structure runs at every scope (including `minimal`):
        // structure violations are cheap to detect and are the worst kind of
        // drift to leave for later, so they are non-optional.
        foreach ($this->moduleStructureTargets($changedFiles) as $target) {
            $targets[] = $target;
        }

        // PHPStan DI verification is expensive. We skip it in `minimal` scope
        // unless this is a repo-wide run (where we want full assurance).
        if ($effectiveScope !== VerificationPlan::SCOPE_MINIMAL || $isRepoWide) {
            $phpstanTarget = $this->phpstanDiTarget($changedFiles, $contractMoves);
            if ($phpstanTarget !== null) {
                $targets[] = $phpstanTarget;
            }
        }

        $liveTenancyTarget = $this->liveTenancyTarget($changedFiles);
        if ($liveTenancyTarget !== null) {
            $targets[] = $liveTenancyTarget;
        }

        $capabilityIndexTarget = $this->capabilityIndexTarget($changedFiles);
        if ($capabilityIndexTarget !== null) {
            $targets[] = $capabilityIndexTarget;
        }

        $capabilityCoverageTarget = $this->capabilityCoverageTarget($changedFiles);
        if ($capabilityCoverageTarget !== null) {
            $targets[] = $capabilityCoverageTarget;
        }

        // Scheduled on every run, deliberately, where every other target here is
        // triggered by a matching changed file.
        //
        // The codereview helper scripts exist as one canonical copy in
        // semitexa-dev's resources and four fan-out copies, and the failure mode
        // is someone editing a *copy*. Those copies live in bin/ and in the agent
        // skill directories — none of which is inside a git repository, because
        // the project root is not one. So the edit that this gate exists to catch
        // is precisely the edit that can never appear in a changed-file list.
        // Triggering on paths would guard only the case that was never the
        // problem.
        //
        // It costs a cmp over ~26 small files and skips itself outside the
        // monorepo, so an always-on target is affordable here in a way it would
        // not be for phpunit or phpstan.
        $targets[] = new VerificationTarget(
            type: VerificationTarget::TYPE_SKILL_COPIES,
            id: 'skill_copies:project',
            reason: 'whole agent skills are duplicated into bin/ and one directory per agent runtime, none of which is version-controlled; an edited copy is silently lost on the next sync',
            triggeredBy: [],
            filePath: null,
        );

        // Not at minimal scope: `docs:reference` regenerates the whole
        // reference (class discovery across every package plus a console boot)
        // to diff it, which puts it with phpstan_di in the expensive tier the
        // minimal contract explicitly excludes — see AiVerifyCommandTest's
        // minimal-scope pin.
        if ($effectiveScope !== VerificationPlan::SCOPE_MINIMAL) {
            foreach ($this->docsTargets($changedFiles) as $docsTarget) {
                $targets[] = $docsTarget;
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
     * Layer-1 blast-radius expansion: if any changed file is a deleted /
     * renamed contract whose FQCN is still referenced elsewhere in the
     * project graph, those dependent files are added to the scan input.
     * Composer's classmap supplies the FQCN → file mapping, so the joined
     * paths are repo-canonical even when our packages are symlinked from
     * vendor/. Without this, a vendor DDD rename can ship green because
     * `phpstan_di` never sees the orphaned consumer files.
     *
     * @param list<ChangedFile>          $files
     * @param list<ContractMoveExpansion> $contractMoves
     */
    private function phpstanDiTarget(array $files, array $contractMoves = []): ?VerificationTarget
    {
        $eligible = [];
        foreach ($files as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED) {
                continue;
            }
            if (!str_ends_with($file->path, '.php')) {
                continue;
            }
            if (self::isPhpstanDiIneligibleKind($file->kind)) {
                continue;
            }
            $eligible[] = $file->path;
        }

        $eligibleSeen = array_flip($eligible);
        foreach ($contractMoves as $move) {
            foreach ($move->dependentFiles as $dependent) {
                if (isset($eligibleSeen[$dependent]) || !str_ends_with($dependent, '.php')) {
                    continue;
                }
                // Apply the SAME production-only eligibility as the changed files:
                // a dependent test/fixture/template (.php) must not be injected
                // into phpstan_di, or it produces noisy non-actionable DI failures.
                if (self::isPhpstanDiIneligibleKind($this->classifier->classify($dependent)->kind)) {
                    continue;
                }
                $eligible[]              = $dependent;
                $eligibleSeen[$dependent] = true;
            }
        }

        if ($eligible === []) {
            return null;
        }

        $reason = $contractMoves === []
            ? 'validate Semitexa attribute-only DI via PHPStan rules (semitexa.injectionViaConstructor + siblings)'
            : sprintf(
                'validate Semitexa DI rules + broken-FQCN guard on consumers of %d removed/renamed contract(s) (ep-ai-verify-broken-fqcn-guard)',
                count($contractMoves),
            );

        return new VerificationTarget(
            type: VerificationTarget::TYPE_PHPSTAN_DI,
            id: 'phpstan_di:' . count($eligible) . '-files',
            reason: $reason,
            triggeredBy: $eligible,
            filePath: null,
            testFilter: null,
        );
    }

    /**
     * Schedule the cross-package live-tenancy join whenever production PHP
     * changed. The guard is a single cheap discovery scan, but the defect it
     * catches — a live-bound resource with no declared tenancy posture —
     * is a silent cross-tenant read, so it runs at every scope, like
     * `module_structure`. The join is global by nature (a payload in one
     * package watches a resource in another), so there is no meaningful
     * per-file narrowing.
     *
     * @param list<ChangedFile> $files
     */
    private function liveTenancyTarget(array $files): ?VerificationTarget
    {
        $triggeredBy = [];
        foreach ($files as $file) {
            if ($file->status === ChangedFile::STATUS_DELETED || !str_ends_with($file->path, '.php')) {
                continue;
            }
            if (self::isPhpstanDiIneligibleKind($file->kind)) {
                continue;
            }
            $triggeredBy[] = $file->path;
        }

        if ($triggeredBy === []) {
            return null;
        }

        return new VerificationTarget(
            type: VerificationTarget::TYPE_LIVE_TENANCY,
            id: 'live_tenancy:project',
            reason: 'every live-bound resource (#[WatchScopes]/watchScopes) must declare #[TenantScoped] or #[TenantExempt]; watched scopes must be backed',
            triggeredBy: $triggeredBy,
            filePath: null,
        );
    }

    /**
     * Schedule the capability-index freshness check when package code changed.
     *
     * The index is the only artifact that tells a consumer project about a
     * package it has NOT installed, and it is a snapshot — it goes stale the
     * moment a `#[Capability]` is added, edited or removed, with nothing in the
     * working copy looking any different. `--check` existed from the start and
     * was wired into nothing, which is the same as not existing.
     *
     * Scoped to `packages/`: that is where the declarations the index carries
     * live, and it is also what makes this a monorepo-only gate. A consumer has
     * no `packages/` directory, so the target is never planned there — which is
     * the point, since a consumer cannot regenerate the file and failing their
     * verify over it would be a bug.
     *
     * @param list<ChangedFile> $files
     */
    private function capabilityIndexTarget(array $files): ?VerificationTarget
    {
        $triggeredBy = [];
        foreach ($files as $file) {
            $path = ltrim($file->path, '/');
            if (!str_starts_with($path, 'packages/')) {
                continue;
            }
            // The index file itself counts: a hand-edit is exactly the drift
            // the content hash is there to catch.
            if (!str_ends_with($path, '.php') && $path !== CapabilityIndex::RELATIVE_PATH) {
                continue;
            }
            $triggeredBy[] = $file->path;
        }

        if ($triggeredBy === []) {
            return null;
        }

        return new VerificationTarget(
            type: VerificationTarget::TYPE_CAPABILITY_INDEX,
            id: 'capability_index:project',
            reason: 'the shipped capability index must match what the packages declare — it is how a consumer learns about packages it has not installed',
            triggeredBy: $triggeredBy,
            filePath: null,
        );
    }

    /**
     * Schedule the coverage guard when a package is added, renamed, or loses
     * its declaration.
     *
     * Narrower than the freshness gate next to it, and deliberately so. That
     * one has to react to any `#[Capability]` edit anywhere; this one only has
     * to catch a package appearing without a declaration, or an existing one
     * being deleted — so a `composer.json` (a package is born, or renamed) and
     * the declaration file itself are the whole trigger set. Running it on
     * every package `.php` change would add a passing line to almost every
     * verify in the monorepo, and a gate that always appears and always passes
     * is one people stop reading.
     *
     * Monorepo-scoped for the same reason as the freshness gate: a consumer has
     * no `packages/` tree, so the target is never planned there.
     *
     * @param list<ChangedFile> $files
     */
    private function capabilityCoverageTarget(array $files): ?VerificationTarget
    {
        $triggeredBy = [];
        foreach ($files as $file) {
            $path = ltrim($file->path, '/');
            if (!str_starts_with($path, 'packages/')) {
                continue;
            }

            $isManifest = str_ends_with($path, '/composer.json');
            $isDeclaration = str_ends_with($path, '/' . CapabilityIndex::DECLARATION_PATH);
            if (!$isManifest && !$isDeclaration) {
                continue;
            }

            $triggeredBy[] = $file->path;
        }

        if ($triggeredBy === []) {
            return null;
        }

        return new VerificationTarget(
            type: VerificationTarget::TYPE_CAPABILITY_COVERAGE,
            id: 'capability_coverage:project',
            reason: 'every Composer package must declare what it offers — a package that declares nothing is invisible to any project that has not installed it',
            triggeredBy: $triggeredBy,
            filePath: null,
        );
    }

    /** A kind excluded from the production-only `phpstan_di` target. */
    private static function isPhpstanDiIneligibleKind(string $kind): bool
    {
        return $kind === ChangedFile::KIND_TEST
            || $kind === ChangedFile::KIND_TEST_FIXTURE
            || $kind === ChangedFile::KIND_TEMPLATE
            || $kind === ChangedFile::KIND_NON_PHP
            || $kind === ChangedFile::KIND_CLIENT_SCRIPT;
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

        if ($file->kind === ChangedFile::KIND_NON_PHP
            || $file->kind === ChangedFile::KIND_CLIENT_SCRIPT
            || $file->kind === ChangedFile::KIND_TEMPLATE) {
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
    /**
     * Documentation gates.
     *
     * Two different failures, so two targets. A page can claim an attribute
     * that no longer exists — that is a markdown change, or a rename that left
     * the page behind. And a signature can change without the generated
     * reference being rebuilt, which no amount of reading the prose would
     * catch; only regenerating and comparing does.
     *
     * Both are skipped when semitexa/docs is absent, and the claim check runs
     * against a baseline when the project keeps one, so existing debt does not
     * block unrelated work.
     *
     * @param list<ChangedFile> $changedFiles
     *
     * @return list<VerificationTarget>
     */
    private function docsTargets(array $changedFiles): array
    {
        $touchedMarkdown = false;
        $touchedPhp = false;
        foreach ($changedFiles as $file) {
            if (str_ends_with(strtolower($file->path), '.md')) {
                $touchedMarkdown = true;
            } elseif (str_ends_with(strtolower($file->path), '.php')) {
                $touchedPhp = true;
            }
        }

        $targets = [];

        if ($touchedMarkdown) {
            $input = [];
            $baseline = $this->projectRoot . '/var/docs/docs-lint-baseline.json';
            if (is_file($baseline)) {
                $input['--baseline'] = $baseline;
            }

            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_DOCS,
                id: 'docs:claims',
                reason: 'a documented attribute, command or env key that the framework no longer has',
                triggeredBy: [],
                commandName: 'docs:lint',
                commandInput: $input,
            );
        }

        if ($touchedPhp) {
            $targets[] = new VerificationTarget(
                type: VerificationTarget::TYPE_DOCS,
                id: 'docs:reference',
                reason: 'the generated reference is built from signatures; changing one without rebuilding leaves the page wrong',
                triggeredBy: [],
                commandName: 'docs:reference:generate',
                commandInput: ['--check' => true],
            );
        }

        return $targets;
    }

}
