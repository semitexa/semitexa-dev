<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * Strict allowlist module-structure validator.
 *
 * Walks the entire module tree and answers exactly one question for every
 * directory and file it sees:
 *
 *   "Is this path explicitly allowed by the {@see ModuleStructureSpec}?"
 *
 * If the answer is not yes, a {@see ModuleStructureViolation} is emitted.
 * There is **no** implicit allow — anything not declared in the spec fails.
 *
 * Rule kinds:
 *
 *   - **Per-path directory allowlist** ({@see ModuleStructureRule::$allowedDirectories}):
 *     a directory is allowed at `<path>` only if its name appears in the
 *     parent rule's `allowedDirectories` (or `allowFeatureGrouping` is true).
 *
 *   - **Per-path file allowlist** ({@see ModuleStructureRule::$allowedFiles},
 *     `$allowedFilePatterns`, `$allowAnyFile`): a file at a given path is
 *     allowed only if it matches one of those.
 *
 *   - **Global file-placement** ({@see FilePlacementRule}): a file whose
 *     basename matches the rule's pattern MUST live at (or under) the
 *     rule's `requiredPath`. The canonical case is
 *     `*Command.php` → `Application/Console/Command/`.
 *
 *   - **Package envelope**: package roots must contain `composer.json` and
 *     `src/`, and may only contain entries declared by
 *     {@see ModuleStructureSpec::$packageRootRule}.
 *
 *   - **Package-only directories**: directory names declared in
 *     {@see ModuleStructureSpec::$packageOnlyDirectories} are rejected
 *     inside `src/modules/*` with `module_structure.invalid_layer`.
 */
final class ModuleStructureValidator
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ModuleStructureSpec $spec,
    ) {}

    /**
     * @return list<ModuleStructureViolation>
     */
    public function validate(DetectedModule $module): array
    {
        $absRoot = $this->projectRoot . '/' . $module->relativePath;
        if (!is_dir($absRoot)) {
            return [];
        }

        if ($module->isPackageModule()) {
            return $this->validatePackage($module, $absRoot);
        }

        return $this->validateApplicationModule($module, $absRoot);
    }

    /**
     * @return list<ModuleStructureViolation>
     */
    private function validateApplicationModule(DetectedModule $module, string $absRoot): array
    {
        $violations = [];
        $this->walkCodeRoot(
            module: $module,
            codeRootAbs: $absRoot,
            isApplicationModule: true,
            violations: $violations,
        );
        return $violations;
    }

    /**
     * @return list<ModuleStructureViolation>
     */
    private function validatePackage(DetectedModule $module, string $absRoot): array
    {
        $violations = [];

        // 0. Production-package-pollution: demo / sandbox / playground /
        //    example / test-app / fake / experimental folders must not
        //    appear ANYWHERE under a production package tree. The scan is
        //    recursive and runs first so a `Demo/` deep inside a sub-tree
        //    still surfaces with the dedicated code (and a concrete
        //    "move to src/modules/" fix), not the generic
        //    `unknown_directory`.
        $this->scanForProductionPackagePollution(
            module: $module,
            absDir: $absRoot,
            relInsidePackage: '',
            violations: $violations,
        );

        // 1. Package envelope: required entries must exist at the package root.
        foreach ($this->spec->requiredPackageRootEntries as $required) {
            $abs = $absRoot . '/' . $required;
            if (!file_exists($abs)) {
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_MISSING_REQUIRED_PATH,
                    module: $module->relativePath,
                    path: $module->relativePath . '/' . $required,
                    message: sprintf(
                        "Package '%s' is missing required entry '%s/'.",
                        $module->relativePath,
                        $required,
                    ),
                    expected: 'Package root must contain ' . $required,
                    actual: 'missing',
                    suggestedFix: 'Create ' . $required . ' at the package root, or do not treat this directory as a Semitexa package.',
                );
            }
        }

        // 2. Package envelope: every entry at the package root must be allowed
        //    by the packageRootRule.
        $envelope = $this->spec->packageRootRule;
        foreach ($this->scanDirs($absRoot) as $dir) {
            if ($envelope->permitsDirectory($dir)) {
                continue;
            }
            $violations[] = new ModuleStructureViolation(
                code: ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
                module: $module->relativePath,
                path: $module->relativePath . '/' . $dir,
                message: sprintf("Unknown package-root directory: '%s/'.", $dir),
                expected: 'one of: ' . implode(', ', $envelope->allowedDirectories),
                actual: $dir . '/',
                suggestedFix: sprintf(
                    "Move '%s/' under src/, tests/, docs/, resources/, tools/, or another canonical package-root directory listed in the spec.",
                    $dir,
                ),
            );
        }
        foreach ($this->scanFiles($absRoot) as $file) {
            if ($envelope->permitsFile($file)) {
                continue;
            }
            $violations[] = new ModuleStructureViolation(
                code: ModuleStructureViolation::CODE_INVALID_ROOT_FILE,
                module: $module->relativePath,
                path: $module->relativePath . '/' . $file,
                message: sprintf("Unknown package-root file: '%s'.", $file),
                expected: 'package metadata/config files only — see ' . ModuleStructureSpecLoader::SPEC_REL_PATH,
                actual: $file,
                suggestedFix: sprintf(
                    "Move '%s' under src/, docs/, tests/, resources/, or add the file (or its pattern) to the spec at %s if it is genuine package metadata.",
                    $file,
                    ModuleStructureSpecLoader::SPEC_REL_PATH,
                ),
            );
        }

        // 3. Package code root: walk `src/` against the codeRootRules.
        $srcAbs = $absRoot . '/src';
        if (is_dir($srcAbs)) {
            $this->walkCodeRoot(
                module: $module,
                codeRootAbs: $srcAbs,
                isApplicationModule: false,
                violations: $violations,
            );
        }

        return $violations;
    }

    /**
     * @param list<ModuleStructureViolation> $violations
     */
    private function walkCodeRoot(
        DetectedModule $module,
        string $codeRootAbs,
        bool $isApplicationModule,
        array &$violations,
    ): void {
        $this->walkPath(
            module: $module,
            absDir: $codeRootAbs,
            specPath: ModuleStructureSpec::TOP_LEVEL_KEY,
            relInsideCodeRoot: '',
            isApplicationModule: $isApplicationModule,
            codeRootRelToProject: $this->codeRootRelativeToProject($module),
            violations: $violations,
            ruleOverride: $this->effectiveTopLevelRule($module),
        );
    }

    /**
     * Build the *effective* top-level rule for one module:
     *
     *   - For application modules: the global `top_level` rule (with
     *     `packageOnlyDirectories` filtered out — the existing
     *     `module_structure.invalid_layer` check handles them).
     *   - For packages: the global `top_level` rule, plus any
     *     additional directories / files declared in
     *     {@see ModuleStructureSpec::$packageSpecificCodeRoot} for
     *     this package's name. Used for narrow framework-only layers
     *     (Container, Composer, Lifecycle, Console-top-level) and
     *     entry-point class files at semitexa-core's source root.
     *
     * Returning `null` means "use the global `top_level` rule unchanged".
     */
    private function effectiveTopLevelRule(DetectedModule $module): ?ModuleStructureRule
    {
        if (!$module->isPackageModule()) {
            return null;
        }
        $extraDirs  = $this->spec->packageSpecificDirectories($module->name);
        $extraFiles = $this->spec->packageSpecificFiles($module->name);
        if ($extraDirs === [] && $extraFiles === []) {
            return null;
        }
        $base = $this->spec->ruleForInPackage($module->name, ModuleStructureSpec::TOP_LEVEL_KEY);
        if ($base === null) {
            return null;
        }
        return new ModuleStructureRule(
            path: ModuleStructureSpec::TOP_LEVEL_KEY,
            allowedDirectories: array_values(array_unique(array_merge($base->allowedDirectories, $extraDirs))),
            allowedFiles: array_values(array_unique(array_merge($base->allowedFiles, $extraFiles))),
            allowedFilePatterns: $base->allowedFilePatterns,
            allowFeatureGrouping: $base->allowFeatureGrouping,
            allowAnyFile: $base->allowAnyFile,
            rationale: $base->rationale,
        );
    }

    /**
     * Recursive walker. `$specPath` is the synthetic spec key for the
     * current location ("top_level" → "Application" → "Application/Payload" …).
     * `$relInsideCodeRoot` is the path inside the code root used to build
     * file-system-style relative paths in violation messages.
     *
     * @param list<ModuleStructureViolation> $violations
     */
    private function walkPath(
        DetectedModule $module,
        string $absDir,
        string $specPath,
        string $relInsideCodeRoot,
        bool $isApplicationModule,
        string $codeRootRelToProject,
        array &$violations,
        ?ModuleStructureRule $ruleOverride = null,
    ): void {
        // Caller may supply an effective rule for this exact level — used
        // by `walkCodeRoot` to merge per-package allowlists into the global
        // `top_level` rule without rewriting the spec.
        $rule = $ruleOverride ?? $this->spec->ruleForInPackage(
            $module->isPackageModule() ? $module->name : null,
            $specPath,
        );
        if ($rule === null) {
            // Defensive: the recursion must never enter a path with no rule.
            // Caller is expected to validate the rule exists for the parent
            // before descending — this branch indicates a spec bug.
            return;
        }

        // Phase 2: explicit opt-out. An opaque_internal rule means the spec
        // author knowingly defers child validation. Stop descending — but we
        // still apply file-placement rules at this level via the descent
        // skip. The directory is permitted; its contents are not scanned.
        if ($rule->isOpaqueInternal()) {
            return;
        }

        // ----- directory children -----
        foreach ($this->scanDirs($absDir) as $childDir) {
            $childRelInsideCode = $relInsideCodeRoot === '' ? $childDir : $relInsideCodeRoot . '/' . $childDir;
            $reportPath = $codeRootRelToProject . '/' . $childRelInsideCode;

            // Application-only check: package-only directory names rejected here.
            if ($isApplicationModule
                && $relInsideCodeRoot === ''
                && $this->spec->isPackageOnlyDirectory($childDir)
            ) {
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_INVALID_LAYER,
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "'%s/' is a package-only layer and is not allowed inside src/modules/.",
                        $childDir,
                    ),
                    expected: 'Application/ or Domain/ sub-tree per the canonical module layout.',
                    actual: $childDir . '/',
                    suggestedFix: sprintf(
                        "Move '%s/' contents into Application/ or Domain/. Application modules consume framework attributes/commands; they do not declare them.",
                        $childDir,
                    ),
                );
                continue;
            }

            if ($rule->allowFeatureGrouping) {
                // Feature grouping: any sub-folder name allowed; descend with
                // the SAME rule so file-placement and grouping continue to
                // apply at any depth.
                $this->walkFeatureGrouping(
                    module: $module,
                    absDir: $absDir . '/' . $childDir,
                    rule: $rule,
                    relInsideCodeRoot: $childRelInsideCode,
                    codeRootRelToProject: $codeRootRelToProject,
                    violations: $violations,
                );
                continue;
            }

            // Phase 2: silent skipping is forbidden. A directory permitted at
            // top level by the package-specific allowlist (e.g. `Container`
            // for `semitexa-core`) MUST have an explicit rule — either
            // `deep_validated` with declared children, or
            // `opaque_internal` with a documented reason. If the rule is
            // missing, the spec is incomplete: emit
            // `module_structure.opaque_marker_required` so the gap is
            // visible in NDJSON.
            if ($specPath === ModuleStructureSpec::TOP_LEVEL_KEY
                && $module->isPackageModule()
                && in_array($childDir, $this->spec->packageSpecificDirectories($module->name), true)
                && !$this->spec->isPathDeclaredInPackage($module->name, $childDir)
            ) {
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_OPAQUE_MARKER_REQUIRED,
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "'%s/' is permitted at the package source root for '%s' but has no validation rule (Phase 2: silent skipping is forbidden).",
                        $childDir,
                        $module->name,
                    ),
                    expected: sprintf(
                        "an explicit ModuleStructureRule(path: '%s', mode: deep_validated|opaque_internal|leaf_files_only) entry in %s",
                        $childDir,
                        ModuleStructureSpecLoader::SPEC_REL_PATH,
                    ),
                    actual: 'no rule registered for "' . $childDir . '"',
                    suggestedFix: sprintf(
                        "Add a ModuleStructureRule(path: '%s', mode: ModuleStructureRule::MODE_DEEP_VALIDATED, allowedDirectories: [...], allowedFiles: [...]) entry. If deep validation is not yet feasible, mark mode: MODE_OPAQUE_INTERNAL with opaqueReason / opaqueOwner / opaqueTodo and document it in MODULE_STRUCTURE.md.",
                        $childDir,
                    ),
                );
                continue;
            }

            if (!in_array($childDir, $rule->allowedDirectories, true)) {
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_UNKNOWN_DIRECTORY,
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "Unknown directory: '%s/' is not declared as a child of '%s'.",
                        $childDir,
                        $this->humaniseSpecPath($specPath),
                    ),
                    expected: $rule->allowedDirectories === []
                        ? sprintf(
                            "no sub-directories allowed under '%s' — see %s",
                            $this->humaniseSpecPath($specPath),
                            ModuleStructureSpecLoader::SPEC_REL_PATH,
                        )
                        : 'one of: ' . implode(', ', $rule->allowedDirectories),
                    actual: $childDir . '/',
                    suggestedFix: $this->buildDirectorySuggestion($specPath, $childDir, $rule),
                );
                // The directory itself is rejected, but we still scan its
                // descendants for file-placement violations so an AI agent
                // gets the specific (and more actionable) signal — e.g.
                // `command_wrong_location` on a *Command.php inside an
                // invented `Console/Command/` tree.
                $this->scanForMisplacedFiles(
                    module: $module,
                    absDir: $absDir . '/' . $childDir,
                    relInsideCodeRoot: $childRelInsideCode,
                    codeRootRelToProject: $codeRootRelToProject,
                    violations: $violations,
                );
                continue;
            }

            // Allowed child: descend with the rule for the new spec path.
            $childSpecPath = $specPath === ModuleStructureSpec::TOP_LEVEL_KEY
                ? $childDir
                : $specPath . '/' . $childDir;

            if (!$this->spec->isPathDeclaredInPackage(
                $module->isPackageModule() ? $module->name : null,
                $childSpecPath,
            )) {
                // The parent permitted the child name but the spec lacks a
                // rule for the child path — explicit "undeclared".
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_UNDECLARED_PATH,
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "Path '%s' is allowed as a child of '%s' but has no rule of its own — its contents cannot be validated.",
                        $childDir,
                        $this->humaniseSpecPath($specPath),
                    ),
                    expected: 'a ModuleStructureRule with path "' . $childSpecPath . '" in ' . ModuleStructureSpecLoader::SPEC_REL_PATH,
                    actual: 'no rule registered for "' . $childSpecPath . '"',
                    suggestedFix: sprintf(
                        "Add a ModuleStructureRule(path: '%s', allowedDirectories: [...], allowAnyFile: true|false) entry to %s. Until then the validator must reject contents below this path.",
                        $childSpecPath,
                        ModuleStructureSpecLoader::SPEC_REL_PATH,
                    ),
                );
                continue;
            }

            $this->walkPath(
                module: $module,
                absDir: $absDir . '/' . $childDir,
                specPath: $childSpecPath,
                relInsideCodeRoot: $childRelInsideCode,
                isApplicationModule: $isApplicationModule,
                codeRootRelToProject: $codeRootRelToProject,
                violations: $violations,
            );
        }

        // ----- file children -----
        foreach ($this->scanCodeRootFiles($absDir) as $file) {
            $fileRelInsideCode = $relInsideCodeRoot === '' ? $file : $relInsideCodeRoot . '/' . $file;
            $reportPath = $codeRootRelToProject . '/' . $fileRelInsideCode;

            // Global file-placement: if the basename matches a placement rule,
            // it MUST live under the rule's required path. Mismatch → emit
            // the rule-specific code (e.g. command_wrong_location). The
            // current rule is forwarded so the placement check can defer
            // when the local leaf rule explicitly accepts this file. The
            // absolute file path is forwarded so the placement check can
            // inspect content when the file lies under an exempt path
            // (closes the Domain/Command loophole).
            $placementMiss = $this->checkFilePlacement($file, $relInsideCodeRoot, $rule, $absDir . '/' . $file);
            if ($placementMiss !== null) {
                $violations[] = new ModuleStructureViolation(
                    code: $placementMiss['code'],
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "%s file '%s' is at the wrong path. Semitexa requires it to live under '%s/'.",
                        ucfirst($placementMiss['description']),
                        $file,
                        $placementMiss['requiredPath'],
                    ),
                    expected: $codeRootRelToProject . '/' . $placementMiss['requiredPath'] . '/',
                    actual: $codeRootRelToProject . '/' . ($relInsideCodeRoot === '' ? '' : $relInsideCodeRoot . '/'),
                    suggestedFix: sprintf(
                        "Move '%s' into '%s/%s/' and update its namespace to match. The path is enforced by the spec at %s.",
                        $file,
                        $codeRootRelToProject,
                        $placementMiss['requiredPath'],
                        ModuleStructureSpecLoader::SPEC_REL_PATH,
                    ),
                );
                continue;
            }

            if (!$rule->permitsFile($file)) {
                $violations[] = new ModuleStructureViolation(
                    code: $relInsideCodeRoot === ''
                        ? ModuleStructureViolation::CODE_INVALID_ROOT_FILE
                        : ModuleStructureViolation::CODE_INVALID_LOCATION,
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "File '%s' is not allowed at '%s'.",
                        $file,
                        $this->humaniseSpecPath($specPath),
                    ),
                    expected: $this->describeFileAllowance($rule),
                    actual: $file,
                    suggestedFix: $this->buildFileSuggestion($specPath, $file),
                );
            }
        }
    }

    /**
     * Walk a feature-grouping subfolder. Any directory name is allowed at any
     * depth (sub-features like `Customer/Order/`), but file rules — including
     * the per-rule allowAnyFile flag and global file-placement rules — keep
     * applying.
     *
     * @param list<ModuleStructureViolation> $violations
     */
    private function walkFeatureGrouping(
        DetectedModule $module,
        string $absDir,
        ModuleStructureRule $rule,
        string $relInsideCodeRoot,
        string $codeRootRelToProject,
        array &$violations,
    ): void {
        foreach ($this->scanDirs($absDir) as $childDir) {
            $this->walkFeatureGrouping(
                module: $module,
                absDir: $absDir . '/' . $childDir,
                rule: $rule,
                relInsideCodeRoot: $relInsideCodeRoot . '/' . $childDir,
                codeRootRelToProject: $codeRootRelToProject,
                violations: $violations,
            );
        }
        foreach ($this->scanCodeRootFiles($absDir) as $file) {
            $reportPath = $codeRootRelToProject . '/' . $relInsideCodeRoot . '/' . $file;
            $placementMiss = $this->checkFilePlacement($file, $relInsideCodeRoot, $rule, $absDir . '/' . $file);
            if ($placementMiss !== null) {
                $violations[] = new ModuleStructureViolation(
                    code: $placementMiss['code'],
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "%s file '%s' is at the wrong path. Semitexa requires it to live under '%s/'.",
                        ucfirst($placementMiss['description']),
                        $file,
                        $placementMiss['requiredPath'],
                    ),
                    expected: $codeRootRelToProject . '/' . $placementMiss['requiredPath'] . '/',
                    actual: $codeRootRelToProject . '/' . $relInsideCodeRoot . '/',
                    suggestedFix: sprintf(
                        "Move '%s' into '%s/%s/'.",
                        $file,
                        $codeRootRelToProject,
                        $placementMiss['requiredPath'],
                    ),
                );
                continue;
            }
            if (!$rule->permitsFile($file)) {
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_INVALID_LOCATION,
                    module: $module->relativePath,
                    path: $reportPath,
                    message: sprintf(
                        "File '%s' is not allowed inside the '%s/' feature grouping.",
                        $file,
                        $rule->path,
                    ),
                    expected: $this->describeFileAllowance($rule),
                    actual: $file,
                    suggestedFix: 'Move the file to a path whose rule permits it (see ' . ModuleStructureSpecLoader::SPEC_REL_PATH . ').',
                );
            }
        }
    }

    /**
     * Walk a package tree looking for forbidden names that signal demo /
     * sandbox / experimental code shipped inside a production package.
     * The check is recursive: a `Demo/` at any depth fires
     * {@see ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION}.
     *
     * The violation message ALWAYS recommends `src/modules/` as the canonical
     * home for local sandbox modules — that is where AI agents should put
     * demo code so production packages stay clean.
     *
     * Skips the whole `tests/` subtree under the package: test fixtures and
     * test cases are allowed to use words like `DemoArticleHandler` or live
     * in `tests/Fixtures/Demo/` without tripping the rule.
     *
     * @param list<ModuleStructureViolation> $violations
     */
    private function scanForProductionPackagePollution(
        DetectedModule $module,
        string $absDir,
        string $relInsidePackage,
        array &$violations,
    ): void {
        if (!$module->isPackageModule()) {
            return;
        }
        if (!is_dir($absDir)) {
            return;
        }
        foreach ($this->scanDirs($absDir) as $childDir) {
            $childRel = $relInsidePackage === '' ? $childDir : $relInsidePackage . '/' . $childDir;

            // tests/ is the legitimate home for fixtures named after demos.
            // The package envelope rule decides whether tests/ is allowed at
            // the top — pollution rule simply does not enter it.
            if ($relInsidePackage === '' && $childDir === 'tests') {
                continue;
            }

            if ($this->spec->isForbiddenInProductionPackages($childDir)) {
                $violations[] = new ModuleStructureViolation(
                    code: ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION,
                    module: $module->relativePath,
                    path: $module->relativePath . '/' . $childRel,
                    message: sprintf(
                        "'%s/' is a demo/sandbox/example folder name and is not allowed inside a production package. "
                        . 'Production packages must not ship demo, sandbox, playground, example, fake, or experimental code. '
                        . 'Local demo modules belong under `src/modules/`.',
                        $childDir,
                    ),
                    expected: 'src/modules/<demo-module-name>/  (or `' . $module->relativePath . '/tests/Fixtures/' . $childDir . '/` if it is genuinely a test fixture)',
                    actual: $module->relativePath . '/' . $childRel . '/',
                    suggestedFix: sprintf(
                        "Move '%s' out of `%s`. If the contents are demo / showcase / sandbox code, relocate to `src/modules/<your-demo-name>/` (the project's local sandbox area). If the contents are test fixtures used by `%s/tests/`, relocate to `%s/tests/Fixtures/%s/`. If it is dead code, delete it. Do **not** add the name to the structure spec — production packages are explicitly forbidden from carrying demo code.",
                        $childRel,
                        $module->relativePath,
                        $module->relativePath,
                        $module->relativePath,
                        $childDir,
                    ),
                );
                // The directory itself is rejected; do not recurse into it.
                // Other descendants of the package are still scanned by the
                // continuation of the loop.
                continue;
            }

            $this->scanForProductionPackagePollution(
                module: $module,
                absDir: $absDir . '/' . $childDir,
                relInsidePackage: $childRel,
                violations: $violations,
            );
        }
    }

    /**
     * Walk an *already-rejected* directory subtree purely to surface
     * file-placement violations. This way an AI agent that drops a
     * misplaced `*Command.php` into an invented `Console/Command/` directory
     * sees both `unknown_directory` (the parent layer is wrong) AND
     * `command_wrong_location` (the specific file is wrong, expected
     * Application/Console/Command/) — both are actionable, neither is noise.
     *
     * @param list<ModuleStructureViolation> $violations
     */
    private function scanForMisplacedFiles(
        DetectedModule $module,
        string $absDir,
        string $relInsideCodeRoot,
        string $codeRootRelToProject,
        array &$violations,
    ): void {
        if (!is_dir($absDir)) {
            return;
        }
        foreach ($this->scanCodeRootFiles($absDir) as $file) {
            $miss = $this->checkFilePlacement(
                basename: $file,
                relInsideCodeRoot: $relInsideCodeRoot,
                currentRule: null,
                absFilePath: $absDir . '/' . $file,
            );
            if ($miss === null) {
                continue;
            }
            $violations[] = new ModuleStructureViolation(
                code: $miss['code'],
                module: $module->relativePath,
                path: $codeRootRelToProject . '/' . $relInsideCodeRoot . '/' . $file,
                message: sprintf(
                    "%s file '%s' is at the wrong path. Semitexa requires it to live under '%s/'.",
                    ucfirst($miss['description']),
                    $file,
                    $miss['requiredPath'],
                ),
                expected: $codeRootRelToProject . '/' . $miss['requiredPath'] . '/',
                actual: $codeRootRelToProject . '/' . $relInsideCodeRoot . '/',
                suggestedFix: sprintf(
                    "Move '%s' into '%s/%s/'.",
                    $file,
                    $codeRootRelToProject,
                    $miss['requiredPath'],
                ),
            );
        }
        foreach ($this->scanDirs($absDir) as $childDir) {
            $this->scanForMisplacedFiles(
                module: $module,
                absDir: $absDir . '/' . $childDir,
                relInsideCodeRoot: $relInsideCodeRoot . '/' . $childDir,
                codeRootRelToProject: $codeRootRelToProject,
                violations: $violations,
            );
        }
    }

    /**
     * @return array{code: string, description: string, requiredPath: string}|null
     */
    private function checkFilePlacement(
        string $basename,
        string $relInsideCodeRoot,
        ?ModuleStructureRule $currentRule = null,
        ?string $absFilePath = null,
    ): ?array {
        foreach ($this->spec->filePlacement as $placement) {
            if (!$placement->matches($basename)) {
                continue;
            }
            if ($placement->isUnderRequiredPath($relInsideCodeRoot)) {
                return null;
            }
            if ($placement->isUnderExemptPath($relInsideCodeRoot)) {
                // Path-level exemption hit. Read file content (if available)
                // and consult the rule's forbidden-content patterns; if any
                // signal an executable instance of the placement target
                // (e.g. `#[AsCommand]` or `extends BaseCommand` inside
                // `Domain/Command/`), revoke the exemption and fail.
                $content = $absFilePath !== null && is_file($absFilePath)
                    ? @file_get_contents($absFilePath)
                    : null;
                if ($content !== false && $placement->contentRevokesExemption($content)) {
                    return [
                        'code'         => $placement->code,
                        'description'  => $placement->description,
                        'requiredPath' => $placement->requiredPath,
                    ];
                }
                return null;
            }
            // The local leaf rule may EXPLICITLY accept this file via an
            // exact filename or an allowedFilePattern. When it does, defer
            // to that local declaration — the file-placement rule is for
            // catching drift, not for overriding intentional cross-tree
            // exceptions like `semitexa-core/src/Console/Command/*Command.php`
            // (Core's first-party commands, framework infrastructure).
            //
            // `allowAnyFile: true` is NOT explicit acceptance — it is a
            // generic permit. Only `allowedFiles` or `allowedFilePatterns`
            // matches count.
            if ($currentRule !== null && $this->ruleExplicitlyAcceptsFile($currentRule, $basename)) {
                return null;
            }
            return [
                'code'         => $placement->code,
                'description'  => $placement->description,
                'requiredPath' => $placement->requiredPath,
            ];
        }
        return null;
    }

    private function ruleExplicitlyAcceptsFile(ModuleStructureRule $rule, string $basename): bool
    {
        foreach ($rule->excludedFilePatterns as $excluded) {
            if (preg_match($excluded, $basename) === 1) {
                return false;
            }
        }
        if (in_array($basename, $rule->allowedFiles, true)) {
            return true;
        }
        foreach ($rule->allowedFilePatterns as $pattern) {
            if (preg_match($pattern, $basename) === 1) {
                return true;
            }
        }
        return false;
    }

    private function codeRootRelativeToProject(DetectedModule $module): string
    {
        return $module->isPackageModule()
            ? $module->relativePath . '/src'
            : $module->relativePath;
    }

    private function humaniseSpecPath(string $specPath): string
    {
        return $specPath === ModuleStructureSpec::TOP_LEVEL_KEY ? 'module root' : $specPath;
    }

    private function describeFileAllowance(ModuleStructureRule $rule): string
    {
        if ($rule->allowAnyFile) {
            return 'any file';
        }
        $bits = [];
        if ($rule->allowedFiles !== []) {
            $bits[] = 'one of: ' . implode(', ', $rule->allowedFiles);
        }
        foreach ($rule->allowedFilePatterns as $pat) {
            $bits[] = 'matching pattern ' . $pat;
        }
        return $bits === [] ? 'no files allowed at this path' : implode(' or ', $bits);
    }

    private function buildDirectorySuggestion(string $specPath, string $childDir, ModuleStructureRule $rule): string
    {
        if ($specPath === ModuleStructureSpec::TOP_LEVEL_KEY) {
            return sprintf(
                "Move the contents of '%s/' into a canonical top-level directory (Application/, Domain/, Context/, Configuration/, Update/, Static/, View/) or extract into its own module under src/modules/. Then update the spec at %s if a new top-level layer is genuinely needed.",
                $childDir,
                ModuleStructureSpecLoader::SPEC_REL_PATH,
            );
        }
        if ($specPath === 'Application') {
            return sprintf(
                "Move '%s/' into the appropriate Application sub-directory (Payload/, Resource/, Handler/, Service/, Console/, Update/, Static/, View/, Component/). If the contents are persistence, move them under Domain/. If you genuinely need a new Application sub-tree, declare it in %s first.",
                $childDir,
                ModuleStructureSpecLoader::SPEC_REL_PATH,
            );
        }
        if ($specPath === 'Domain') {
            return sprintf(
                "Move '%s/' into the appropriate Domain sub-directory (Model/, Repository/, Contract/, Exception/, Service/, Event/).",
                $childDir,
            );
        }
        return sprintf(
            "Either rename '%s/' to one of the allowed children, move its contents, or extend the spec at %s.",
            $childDir,
            ModuleStructureSpecLoader::SPEC_REL_PATH,
        );
    }

    private function buildFileSuggestion(string $specPath, string $file): string
    {
        if ($specPath === ModuleStructureSpec::TOP_LEVEL_KEY) {
            return sprintf(
                "Module root holds no source files. Move '%s' under Application/<sub-tree>/ or Domain/<sub-tree>/.",
                $file,
            );
        }
        return sprintf(
            "Move '%s' to a sub-tree whose rule permits files of this kind (see %s).",
            $file,
            ModuleStructureSpecLoader::SPEC_REL_PATH,
        );
    }

    /**
     * @return list<string>
     */
    private function scanDirs(string $abs): array
    {
        if (!is_dir($abs)) {
            return [];
        }
        $out = [];
        foreach (scandir($abs) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($abs . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * @return list<string>
     */
    private function scanFiles(string $abs): array
    {
        if (!is_dir($abs)) {
            return [];
        }
        $out = [];
        foreach (scandir($abs) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_file($abs . '/' . $entry)) {
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Same as {@see scanFiles} but additionally filters out scaffold-marker
     * dotfiles (`.gitkeep`, `.gitignore`, `.keep`, `.DS_Store`, …) that are
     * universal repo conventions, not architectural files. Used by every
     * walker inside the code root; the package envelope scan keeps using
     * `scanFiles` directly because it has its own allowedFiles list for
     * legitimate package-root dotfiles like `.env.default`.
     *
     * @return list<string>
     */
    private function scanCodeRootFiles(string $abs): array
    {
        $out = [];
        foreach ($this->scanFiles($abs) as $entry) {
            if (str_starts_with($entry, '.')) {
                continue;
            }
            $out[] = $entry;
        }
        return $out;
    }
}
