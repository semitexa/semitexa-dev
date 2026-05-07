<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\DetectedModule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\LocalModuleStructureExtension;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureRule;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpec;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureSpecLoader;
use Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureTargetResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Explains a file or directory path using global module-structure rules
 * and any package-local extension. Powers `ai:ask path --path=…`.
 *
 * Output (JSON):
 *
 *   {
 *     "kind": "path_explanation",
 *     "path": "<repo-relative path>",
 *     "package": "<short package name>" | null,
 *     "module": "<application module name>" | null,
 *     "module_kind": "package" | "application" | null,
 *     "rule_scope": "global" | "local" | "none",
 *     "status": "allowed" | "invalid" | "unresolved" | "outside_module",
 *     "is_directory": bool,
 *     "is_file": bool,
 *     "purpose": "<one-line description>",
 *     "reason": "<rule rationale>",
 *     "public_api": bool | null,
 *     "docs_used": ["<repo-relative path>", ...],
 *     "executable_rules_used": ["<repo-relative path>", ...],
 *     "warnings": ["..."],
 *     "suggested_action": "<short remediation hint>" | null,
 *     "rule": { ... rule metadata ... } | null
 *   }
 *
 * The output is deterministic and machine-friendly; ai:ask delegates
 * directly so consumers receive the same envelope.
 */
#[AsCommand(name: 'dev:graph:path', description: 'Explain a file/directory path using global module-structure rules + package-local extension (if any)')]
final class DevGraphPathCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:path');
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Repo-relative path to explain (file or directory)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON envelope (default for ai:ask)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawPath = (string) ($input->getOption('path') ?? '');
        if ($rawPath === '') {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => 'missing required option: --path',
            ], JSON_UNESCAPED_SLASHES));
            return Command::FAILURE;
        }

        $projectRoot = $this->getProjectRoot();
        $envelope = $this->explain($projectRoot, $rawPath);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        // Human-friendly fallback: render the same envelope as a short
        // structured summary. Keeps the field set identical so consumers
        // can choose either output.
        $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function explain(string $projectRoot, string $rawPath): array
    {
        $relPath = $this->normalizePath($rawPath);
        $abs = $projectRoot . '/' . $relPath;
        $isDir = is_dir($abs);
        $isFile = is_file($abs);

        $resolver = new ModuleStructureTargetResolver($projectRoot);
        $module = $resolver->resolveOne($relPath);

        $loader = new ModuleStructureSpecLoader($projectRoot);
        try {
            $spec = $loader->load();
        } catch (\Throwable $e) {
            return [
                'kind'      => 'path_explanation',
                'path'      => $relPath,
                'status'    => 'spec_load_failed',
                'rule_scope' => 'none',
                'reason'    => $e->getMessage(),
                'docs_used' => [],
                'executable_rules_used' => [],
                'warnings'  => [],
            ];
        }

        $globalDocPath = 'packages/semitexa-docs/docs/MODULE_STRUCTURE.md';
        $globalRulesPath = ModuleStructureSpecLoader::SPEC_REL_PATH;

        if ($module === null) {
            return [
                'kind'        => 'path_explanation',
                'path'        => $relPath,
                'package'     => null,
                'module'      => null,
                'module_kind' => null,
                'rule_scope'  => 'none',
                'status'      => 'outside_module',
                'is_directory' => $isDir,
                'is_file'     => $isFile,
                'purpose'     => 'Path is not inside any Semitexa package or application module.',
                'reason'      => 'Module structure rules apply only to `packages/semitexa-*/` and `src/modules/*/`. Other paths are outside the validator.',
                'public_api'  => null,
                'docs_used'   => [$globalDocPath],
                'executable_rules_used' => [$globalRulesPath],
                'warnings'    => [],
                'suggested_action' => null,
                'rule'        => null,
            ];
        }

        $localExt = $module->isPackageModule() ? $spec->localExtensionFor($module->name) : null;
        $localDocPath = $localExt?->docPath;
        $localRulesPath = $module->isPackageModule()
            ? 'packages/' . ($module->isPackageModule() ? 'semitexa-' . $module->name : '') . '/' . ModuleStructureSpecLoader::LOCAL_EXTENSION_REL_PATH
            : null;
        $localRulesExists = $localRulesPath !== null && is_file($projectRoot . '/' . $localRulesPath);

        $docsUsed = [$globalDocPath];
        $rulesUsed = [$globalRulesPath];
        if ($localRulesExists) {
            $rulesUsed[] = $localRulesPath;
        }
        if ($localDocPath !== null && is_file($projectRoot . '/' . $localDocPath)) {
            $docsUsed[] = $localDocPath;
        }

        $warnings = [];
        if ($localRulesExists && ($localDocPath === null || !is_file($projectRoot . '/' . $localDocPath))) {
            $warnings[] = sprintf(
                'Package "%s" has an executable local extension at %s but no human docs at %s — add a companion Markdown doc explaining the extension.',
                $module->name,
                $localRulesPath,
                'packages/semitexa-' . $module->name . '/' . ModuleStructureSpecLoader::LOCAL_EXTENSION_DOC_REL_PATH,
            );
        }

        // Determine the spec path for the requested location relative to the
        // module's code root (`packages/<pkg>/src/` or `src/modules/<mod>/`).
        [$specPath, $insideCodeRoot, $isCodeRootRoot] = $this->locateInsideCodeRoot($module, $relPath);

        if (!$insideCodeRoot) {
            $isApplicationModule = $module->isApplicationModule();
            return [
                'kind'        => 'path_explanation',
                'path'        => $relPath,
                'package'     => $module->isPackageModule() ? $module->name : null,
                'module'      => $module->isApplicationModule() ? $module->name : null,
                'module_kind' => $module->kind,
                'rule_scope'  => 'global',
                'status'      => 'envelope',
                'is_directory' => $isDir,
                'is_file'     => $isFile,
                'purpose'     => $isApplicationModule
                    ? 'Path is at the application-module envelope (not inside src/). Validated against the application-module top-level rule.'
                    : 'Path is at the package envelope (not inside src/). Validated against the global packageRoot rule.',
                'reason'      => $isApplicationModule
                    ? 'Application-module envelopes only allow src/, tests/, and the small metadata file set documented for local modules.'
                    : 'Package envelope holds metadata + canonical sub-roots (composer.json, src/, tests/, docs/, ...).',
                'public_api'  => null,
                'docs_used'   => $docsUsed,
                'executable_rules_used' => $rulesUsed,
                'warnings'    => $warnings,
                'suggested_action' => null,
                'rule'        => $this->serializeRule($isApplicationModule ? $this->effectiveTopLevelRule($spec, $module) : $spec->packageRootRule),
            ];
        }

        // Inside the code root. Look up the rule for the spec path —
        // walking up the parent chain looking for a feature-grouping
        // ancestor (mirrors the validator's walkFeatureGrouping recursion
        // so ai:ask agrees with ai:verify on feature-grouped paths).
        $packageNameForLookup = $module->isPackageModule() ? $module->name : null;
        if ($isCodeRootRoot) {
            $rule = $spec->ruleForInPackage($packageNameForLookup, ModuleStructureSpec::TOP_LEVEL_KEY);
            $inheritedFrom = null;
        } else {
            [$rule, $inheritedFrom] = $this->resolveRuleWithFeatureGroupingInheritance(
                $spec, $packageNameForLookup, $specPath,
            );
        }

        // Determine rule scope: was the resolved rule contributed by the
        // local extension, or is it global?
        $ruleScope = 'global';
        $localOwned = false;
        if ($localExt !== null && isset($localExt->pathRules[$specPath])) {
            $ruleScope = 'local';
            $localOwned = true;
        } elseif ($localExt !== null && $isCodeRootRoot) {
            // Top-level: local extension contributed extra directories/files
            // to the effective rule but the global top-level rule still
            // backs it. We mark scope as 'local' only if the requested
            // path is a top-level dir/file the local extension declares.
            // Otherwise stay 'global'.
        }

        // For top-level subdirectories declared by the local extension,
        // surface them as locally owned even if we look at the directory
        // entry rather than the rule.
        if ($isCodeRootRoot && ($isDir || $isFile)) {
            // Inspect the requested basename to see if local extension
            // declares it.
            $basename = basename($relPath);
            if ($localExt !== null) {
                if ($isDir && in_array($basename, $localExt->topLevelDirectories, true)) {
                    $ruleScope = 'local';
                    $localOwned = true;
                    if (isset($localExt->pathRules[$basename])) {
                        $rule = $localExt->pathRules[$basename];
                        $specPath = $basename;
                    }
                }
                if ($isFile && in_array($basename, $localExt->topLevelFiles, true)) {
                    $ruleScope = 'local';
                    $localOwned = true;
                }
            }
        }

        // Build the response based on whether the rule is allowed/invalid.
        if ($rule !== null && !$isCodeRootRoot) {
            // For files, also confirm the inherited rule actually permits
            // the file basename — otherwise it's invalid even if the
            // ancestor allows feature grouping at the directory level.
            if ($isFile) {
                $basename = basename($relPath);
                if (!$rule->permitsFile($basename)) {
                    return [
                        'kind'        => 'path_explanation',
                        'path'        => $relPath,
                        'package'     => $module->isPackageModule() ? $module->name : null,
                        'module'      => $module->isApplicationModule() ? $module->name : null,
                        'module_kind' => $module->kind,
                        'rule_scope'  => $ruleScope,
                        'status'      => 'invalid',
                        'exists'      => true,
                        'is_directory' => false,
                        'is_file'     => true,
                        'purpose'     => sprintf("File '%s' inside %s", $basename, $specPath),
                        'reason'      => sprintf(
                            "File '%s' is not permitted at spec path '%s' (rule: %s).",
                            $basename, $specPath, $this->describeRuleAllowance($rule),
                        ),
                        'public_api'  => false,
                        'docs_used'   => $docsUsed,
                        'executable_rules_used' => $rulesUsed,
                        'warnings'    => $warnings,
                        'suggested_action' => 'Move the file under the canonical layer that permits its basename.',
                        'rule'        => $this->serializeRule($rule),
                    ];
                }
            }
            return $this->buildAllowedResponse(
                relPath: $relPath, module: $module, spec: $spec,
                specPath: $specPath, rule: $rule, ruleScope: $ruleScope,
                docsUsed: $docsUsed, rulesUsed: $rulesUsed, warnings: $warnings,
                isDir: $isDir, isFile: $isFile, localOwned: $localOwned,
                localExt: $localExt, exists: $isDir || $isFile,
                inheritedFromPath: $inheritedFrom,
            );
        }

        // Path does not exist on disk and is below code root: classify it
        // hypothetically against the spec. If a feature-grouping ancestor
        // would permit it, mark allowed + exists=false.
        if (!$isDir && !$isFile && !$isCodeRootRoot) {
            [$hypoRule, $hypoInherited] = $this->resolveRuleWithFeatureGroupingInheritance(
                $spec, $packageNameForLookup, $specPath,
            );
            if ($hypoRule !== null) {
                return $this->buildAllowedResponse(
                    relPath: $relPath, module: $module, spec: $spec,
                    specPath: $specPath, rule: $hypoRule, ruleScope: 'global',
                    docsUsed: $docsUsed, rulesUsed: $rulesUsed, warnings: $warnings,
                    isDir: false, isFile: false, localOwned: false,
                    localExt: $localExt, exists: false,
                    inheritedFromPath: $hypoInherited,
                );
            }
        }

        // Top-level path or a directory/file explicitly under code root.
        if ($isCodeRootRoot) {
            // Examine the requested basename (if any) against the effective
            // top-level allowlist.
            $basename = $relPath !== '' ? basename($relPath) : '';
            if ($basename === '') {
                return [
                    'kind'        => 'path_explanation',
                    'path'        => $relPath,
                    'package'     => $module->isPackageModule() ? $module->name : null,
                    'module'      => $module->isApplicationModule() ? $module->name : null,
                    'module_kind' => $module->kind,
                    'rule_scope'  => $ruleScope,
                    'status'      => 'allowed',
                    'is_directory' => $isDir,
                    'is_file'     => $isFile,
                    'purpose'     => 'Module code root.',
                    'reason'      => 'Validated against the global top_level rule (with any package-specific additions merged in).',
                    'public_api'  => null,
                    'docs_used'   => $docsUsed,
                    'executable_rules_used' => $rulesUsed,
                    'warnings'    => $warnings,
                    'suggested_action' => null,
                    'rule'        => $rule !== null ? $this->serializeRule($rule) : null,
                ];
            }
            // Determine whether the basename is allowed at top-level.
            $effectiveTop = $this->effectiveTopLevelRule($spec, $module);
            $allowedAtTop = $isDir
                ? $effectiveTop->permitsDirectory($basename)
                : $effectiveTop->permitsFile($basename);
            if (!$allowedAtTop) {
                return [
                    'kind'        => 'path_explanation',
                    'path'        => $relPath,
                    'package'     => $module->isPackageModule() ? $module->name : null,
                    'module'      => $module->isApplicationModule() ? $module->name : null,
                    'module_kind' => $module->kind,
                    'rule_scope'  => 'global',
                    'status'      => 'invalid',
                    'is_directory' => $isDir,
                    'is_file'     => $isFile,
                    'purpose'     => sprintf("'%s' at module root", $basename),
                    'reason'      => sprintf(
                        "Top-level %s '%s' is not in the global allowlist for %s. Local extension does not authorise it for this package.",
                        $isDir ? 'directory' : 'file',
                        $basename,
                        $module->isPackageModule() ? 'this package' : 'application modules',
                    ),
                    'public_api'  => false,
                    'docs_used'   => $docsUsed,
                    'executable_rules_used' => $rulesUsed,
                    'warnings'    => $warnings,
                    'suggested_action' => $isDir
                        ? sprintf("Move '%s/' contents under a canonical top-level layer (Application/, Domain/, Configuration/, ...).", $basename)
                        : sprintf("Move '%s' under Application/<sub-tree>/ or Domain/<sub-tree>/.", $basename),
                    'rule'        => null,
                ];
            }
            // Allowed at top level. If allowed by local extension, surface that.
            return $this->buildAllowedResponse(
                relPath: $relPath, module: $module, spec: $spec,
                specPath: $basename, rule: $rule, ruleScope: $ruleScope,
                docsUsed: $docsUsed, rulesUsed: $rulesUsed, warnings: $warnings,
                isDir: $isDir, isFile: $isFile, localOwned: $localOwned,
                localExt: $localExt,
            );
        }

        // No rule found for the path even after feature-grouping
        // ancestor walk. It's either invalid or unresolved.
        return [
            'kind'        => 'path_explanation',
            'path'        => $relPath,
            'package'     => $module->isPackageModule() ? $module->name : null,
            'module'      => $module->isApplicationModule() ? $module->name : null,
            'module_kind' => $module->kind,
            'rule_scope'  => 'none',
            'status'      => 'unresolved',
            'exists'      => $isDir || $isFile,
            'is_directory' => $isDir,
            'is_file'     => $isFile,
            'purpose'     => sprintf("Path '%s' inside %s", $specPath, $module->relativePath),
            'reason'      => 'No rule registered for this path in either the global spec or the package-local extension. No feature-grouping ancestor permits it either.',
            'public_api'  => null,
            'docs_used'   => $docsUsed,
            'executable_rules_used' => $rulesUsed,
            'warnings'    => $warnings,
            'suggested_action' => 'Resolve as an Architecture Question, then either move the contents into a canonical layer or add an explicit rule (preferred: package-local extension if the layer is package-specific framework primitive).',
            'rule'        => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAllowedResponse(
        string $relPath,
        DetectedModule $module,
        ModuleStructureSpec $spec,
        string $specPath,
        ?ModuleStructureRule $rule,
        string $ruleScope,
        array $docsUsed,
        array $rulesUsed,
        array $warnings,
        bool $isDir,
        bool $isFile,
        bool $localOwned,
        ?LocalModuleStructureExtension $localExt,
        bool $exists = true,
        ?string $inheritedFromPath = null,
    ): array {
        $publicApi = null;
        $reason = $rule?->rationale ?? 'Allowed by spec rule.';
        if ($localOwned && $localExt !== null) {
            $publicApi = true;
            $reason = sprintf(
                'Authorised by the local module-structure extension at packages/semitexa-%s/config/module-structure.php (scope: this package only). %s',
                $module->name,
                $rule?->rationale ?? '',
            );
            $warnings[] = sprintf(
                'This path is allowed only because of the local extension for "%s". The same path is invalid in any other package or application module.',
                $module->name,
            );
        } elseif ($inheritedFromPath !== null) {
            // Feature-grouping inheritance: the rule comes from an
            // ancestor that has allowFeatureGrouping: true.
            $reason = sprintf(
                "Inherited from the '%s' rule (allowFeatureGrouping: true). %s",
                $inheritedFromPath,
                $rule?->rationale ?? '',
            );
            $warnings[] = sprintf(
                "This path is a feature-grouped child under '%s', not a separately declared layer. The validator descends with the parent rule.",
                $inheritedFromPath,
            );
        }
        if (!$exists) {
            $warnings[] = 'Path does not exist on disk; explanation is hypothetical (would be allowed if created).';
        }

        return [
            'kind'        => 'path_explanation',
            'path'        => $relPath,
            'package'     => $module->isPackageModule() ? $module->name : null,
            'module'      => $module->isApplicationModule() ? $module->name : null,
            'module_kind' => $module->kind,
            'rule_scope'  => $ruleScope,
            'status'      => 'allowed',
            'exists'      => $exists,
            'is_directory' => $isDir,
            'is_file'     => $isFile,
            'purpose'     => sprintf("Spec path '%s' inside %s", $specPath, $module->relativePath),
            'reason'      => trim($reason),
            'public_api'  => $publicApi,
            'docs_used'   => $docsUsed,
            'executable_rules_used' => $rulesUsed,
            'warnings'    => $warnings,
            'suggested_action' => null,
            'rule'        => $rule !== null ? $this->serializeRule($rule) : null,
        ];
    }

    /**
     * Mirrors the validator's walkFeatureGrouping recursion: when the
     * exact $specPath has no rule, walk parent paths until we find one
     * with allowFeatureGrouping: true. That ancestor's rule applies to
     * any descendant feature subdirectory at any depth.
     *
     * Returns [rule, inheritedFromPath] where $inheritedFromPath is the
     * spec path of the ancestor that contributed the rule (null if the
     * lookup hit the exact path) or null if no rule applies at all.
     *
     * @return array{0: ?ModuleStructureRule, 1: ?string}
     */
    private function resolveRuleWithFeatureGroupingInheritance(
        ModuleStructureSpec $spec,
        ?string $packageName,
        string $specPath,
    ): array {
        // 1. Direct lookup.
        $direct = $spec->ruleForInPackage($packageName, $specPath);
        if ($direct !== null) {
            return [$direct, null];
        }
        // 2. Walk up the parent chain. Each ancestor whose rule has
        //    allowFeatureGrouping: true contributes its rule to all
        //    descendants. We stop at the first such ancestor (closest to
        //    the requested path).
        $parts = explode('/', $specPath);
        while (count($parts) > 1) {
            array_pop($parts);
            $parent = implode('/', $parts);
            $parentRule = $spec->ruleForInPackage($packageName, $parent);
            if ($parentRule !== null && $parentRule->allowFeatureGrouping) {
                return [$parentRule, $parent];
            }
        }
        // 3. Top-level itself is checked separately by the caller (the
        //    code-root path); if we reach here from below, no inheritance
        //    applies.
        return [null, null];
    }

    private function describeRuleAllowance(ModuleStructureRule $rule): string
    {
        $bits = [];
        if ($rule->allowAnyFile) {
            $bits[] = 'any file';
        }
        if ($rule->allowedFiles !== []) {
            $bits[] = 'one of: ' . implode(', ', $rule->allowedFiles);
        }
        foreach ($rule->allowedFilePatterns as $pat) {
            $bits[] = 'pattern ' . $pat;
        }
        return $bits === [] ? 'no files allowed' : implode('; ', $bits);
    }

    /**
     * @return array{0: string, 1: bool, 2: bool} [specPath, insideCodeRoot, isCodeRootRoot]
     */
    private function locateInsideCodeRoot(DetectedModule $module, string $relPath): array
    {
        if ($module->isPackageModule()) {
            $codeRoot = $module->relativePath . '/src';
        } else {
            $codeRoot = $module->relativePath . '/src';
        }
        if ($relPath === $codeRoot) {
            return ['', true, true];
        }
        $prefix = $codeRoot . '/';
        if (!str_starts_with($relPath, $prefix)) {
            // Outside code root (envelope).
            return ['', false, false];
        }
        $inside = substr($relPath, strlen($prefix));
        // For files, the spec path is the parent dir; the file basename is
        // matched separately. For directories, the spec path IS the dir path.
        $abs = $this->getProjectRoot() . '/' . $relPath;
        if (is_file($abs)) {
            $parent = dirname($inside);
            $specPath = $parent === '.' ? ModuleStructureSpec::TOP_LEVEL_KEY : $parent;
            return [$specPath, true, $parent === '.'];
        }
        // Directory.
        if (str_contains($inside, '/')) {
            return [$inside, true, false];
        }
        // Top-level directory child.
        return [$inside, true, true];
    }

    private function effectiveTopLevelRule(ModuleStructureSpec $spec, DetectedModule $module): ModuleStructureRule
    {
        $base = $spec->ruleForInPackage(
            $module->isPackageModule() ? $module->name : null,
            ModuleStructureSpec::TOP_LEVEL_KEY,
        );
        if ($base === null) {
            // Defensive fallback — should never happen in a well-formed spec.
            return new ModuleStructureRule(path: ModuleStructureSpec::TOP_LEVEL_KEY);
        }
        if (!$module->isPackageModule()) {
            return $base;
        }
        $extraDirs = $spec->packageSpecificDirectories($module->name);
        $extraFiles = $spec->packageSpecificFiles($module->name);
        if ($extraDirs === [] && $extraFiles === []) {
            return $base;
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
     * @return array<string, mixed>
     */
    private function serializeRule(ModuleStructureRule $rule): array
    {
        return [
            'path'                 => $rule->path,
            'mode'                 => $rule->mode,
            'allowedDirectories'   => $rule->allowedDirectories,
            'allowedFiles'         => $rule->allowedFiles,
            'allowedFilePatterns'  => $rule->allowedFilePatterns,
            'excludedFilePatterns' => $rule->excludedFilePatterns,
            'allowFeatureGrouping' => $rule->allowFeatureGrouping,
            'allowAnyFile'         => $rule->allowAnyFile,
            'rationale'            => $rule->rationale,
            'opaqueOwner'          => $rule->opaqueOwner,
            'opaqueReason'         => $rule->opaqueReason,
            'opaqueTodo'           => $rule->opaqueTodo,
        ];
    }

    private function normalizePath(string $raw): string
    {
        $raw = ltrim(str_replace('\\', '/', $raw), '/');
        // Strip trailing slash unless empty.
        if (str_ends_with($raw, '/') && $raw !== '/') {
            $raw = rtrim($raw, '/');
        }
        return $raw;
    }
}
