<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify;

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
        ChangedFile::KIND_HANDLER  => ['semitexa:lint:handlers', 'semitexa:lint:di'],
        ChangedFile::KIND_LISTENER => ['semitexa:lint:di', 'semitexa:lint:scoping'],
        ChangedFile::KIND_PAYLOAD  => ['semitexa:lint:responses', 'semitexa:lint:di'],
        ChangedFile::KIND_RESOURCE => ['semitexa:lint:responses'],
        ChangedFile::KIND_SERVICE  => ['semitexa:lint:di', 'semitexa:lint:scoping'],
        ChangedFile::KIND_CONTRACT => ['semitexa:lint:di'],
        ChangedFile::KIND_TEMPLATE => ['semitexa:lint:templates'],
    ];

    private const ALL_LINTS = [
        'semitexa:lint:handlers',
        'semitexa:lint:di',
        'semitexa:lint:scoping',
        'semitexa:lint:responses',
        'semitexa:lint:templates',
    ];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ChangedFileClassifier $classifier = new ChangedFileClassifier(),
    ) {}

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

        return new VerificationPlan(
            scope: $requestedScope,
            effectiveScope: $effectiveScope,
            changedFiles: $changedFiles,
            targets: $targets,
            expansions: $expansions,
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
                testFilter: $className,
            );
            return;
        }

        if ($file->kind === ChangedFile::KIND_NON_PHP || $file->kind === ChangedFile::KIND_TEMPLATE) {
            return;
        }

        foreach ($this->findMatchingTests($file->path) as $testClass => $testPath) {
            if (isset($phpunitTargets[$testClass])) {
                $phpunitTargets[$testClass] = new VerificationTarget(
                    type: VerificationTarget::TYPE_PHPUNIT,
                    id: "phpunit:{$testClass}",
                    reason: $phpunitTargets[$testClass]->reason,
                    triggeredBy: array_values(array_unique([...$phpunitTargets[$testClass]->triggeredBy, $file->path])),
                    testFilter: $testClass,
                );
                continue;
            }
            $phpunitTargets[$testClass] = new VerificationTarget(
                type: VerificationTarget::TYPE_PHPUNIT,
                id: "phpunit:{$testClass}",
                reason: "matching test found for {$this->classNameFromPath($file->path)}",
                triggeredBy: [$file->path],
                testFilter: $testClass,
            );
        }
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
