<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * Loads the executable module-structure spec from
 * `packages/semitexa-dev/config/module-structure.php` and merges in any
 * package-local additive extensions discovered at
 * `packages/<package>/config/module-structure.php`.
 *
 * Local extensions are strictly additive: they may add per-package top-level
 * directories / root files (and the rules for their contents) but they MUST
 * NOT override the global forbidden rules, the production-pollution
 * deny-list, the `Domain/Contract` `*Interface.php` rule, the `Exception`
 * `*Exception.php` rule, or the singular `Attribute/` convention. Loading
 * fails loudly if any local extension violates these guards.
 *
 * If the spec file is missing the loader throws — there is no implicit
 * fallback. The strict-allowlist contract is meaningless without an explicit
 * spec.
 */
final class ModuleStructureSpecLoader
{
    public const SPEC_REL_PATH                  = 'packages/semitexa-dev/config/module-structure.php';
    public const LOCAL_EXTENSION_REL_PATH       = 'config/module-structure.php';
    public const LOCAL_EXTENSION_DOC_REL_PATH   = 'docs/MODULE_STRUCTURE.md';

    /** @var array<string, array{mtime:int, spec:ModuleStructureSpec}> */
    private array $cache = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $specPathOverride = null,
        private readonly bool $discoverLocalExtensions = true,
    ) {}

    /**
     * Resolution order:
     *
     *   1. `$specPathOverride` if set (test injection / per-call override).
     *   2. `<projectRoot>/packages/semitexa-dev/config/module-structure.php`
     *      — the canonical location when running from the monorepo.
     *   3. The dev package's own bundled spec, located via `__DIR__`. This
     *      ensures the loader works in any consumer project that installs
     *      `semitexa/dev` via Composer (the spec is shipped inside the
     *      package, so it is always present alongside this loader).
     *
     * Step 3 is **not** a fallback to a *different* spec — it is the same
     * spec, located via Composer's autoload path instead of the working
     * directory. It removes any chance of a `RuntimeException` when ai:verify
     * runs against a project that does not literally contain the dev package
     * at the canonical relative path.
     */
    public function load(): ModuleStructureSpec
    {
        $abs = $this->resolveSpecPath();

        if (!is_file($abs)) {
            throw new \RuntimeException(
                'Module structure spec missing at ' . $abs
                . ' — ai:verify cannot enforce a strict allowlist without an explicit spec.'
                . ' See packages/semitexa-docs/docs/MODULE_STRUCTURE.md for the prose mirror.',
            );
        }

        // Cache key blends the global spec mtime with whether local discovery
        // is requested AND a fingerprint of every local extension file's mtime
        // so worker re-loads catch local edits without restart.
        $globalMtime = (int) @filemtime($abs);
        $localFingerprint = $this->discoverLocalExtensions ? $this->localExtensionFingerprint() : 'off';
        $cacheKey = $abs . '|' . $globalMtime . '|' . $localFingerprint;
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey]['spec'];
        }

        $globalSpec = require $abs;
        if (!$globalSpec instanceof ModuleStructureSpec) {
            throw new \RuntimeException(
                'Module structure spec at ' . $abs . ' must return a ' . ModuleStructureSpec::class . ' instance.',
            );
        }

        $merged = $this->discoverLocalExtensions
            ? $this->mergeLocalExtensions($globalSpec)
            : $globalSpec;

        $this->cache[$cacheKey] = ['mtime' => $globalMtime, 'spec' => $merged];
        return $merged;
    }

    private function resolveSpecPath(): string
    {
        if ($this->specPathOverride !== null) {
            return $this->specPathOverride;
        }
        $atProjectRoot = $this->projectRoot . '/' . self::SPEC_REL_PATH;
        if (is_file($atProjectRoot)) {
            return $atProjectRoot;
        }
        // Bundled location, relative to this loader file. Walk up from
        // `<dev>/src/Application/Service/Ai/Verify/Structure/` to `<dev>/config/`.
        return dirname(__DIR__, 6) . '/config/module-structure.php';
    }

    /**
     * Discover every per-package local extension at
     * `packages/<name>/config/module-structure.php`, validate each against
     * the global guard rails, and merge into the supplied global spec.
     * Returns a NEW spec instance — the input is not mutated.
     */
    private function mergeLocalExtensions(ModuleStructureSpec $global): ModuleStructureSpec
    {
        $packagesDir = $this->projectRoot . '/packages';
        if (!is_dir($packagesDir)) {
            return $global;
        }

        $extensions = [];
        $packageScopedRules = $global->packageScopedRules;
        $packageSpecificCodeRoot = $global->packageSpecificCodeRoot;

        foreach (scandir($packagesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            // semitexa-dev owns the GLOBAL spec at the same relative path
            // (`config/module-structure.php`). It is loaded above as the
            // global spec; skip it here so we do not try to re-load it as
            // a local extension.
            if ($entry === 'semitexa-dev') {
                continue;
            }
            $localConfigAbs = $packagesDir . '/' . $entry . '/' . self::LOCAL_EXTENSION_REL_PATH;
            if (!is_file($localConfigAbs)) {
                continue;
            }
            $extension = require $localConfigAbs;
            if (!$extension instanceof LocalModuleStructureExtension) {
                throw new \RuntimeException(sprintf(
                    'Local module structure extension at %s must return a %s instance, got %s.',
                    'packages/' . $entry . '/' . self::LOCAL_EXTENSION_REL_PATH,
                    LocalModuleStructureExtension::class,
                    is_object($extension) ? $extension::class : get_debug_type($extension),
                ));
            }

            $expectedPackage = str_starts_with($entry, 'semitexa-')
                ? substr($entry, strlen('semitexa-'))
                : $entry;
            if ($extension->package !== $expectedPackage) {
                throw new \RuntimeException(sprintf(
                    'Local module structure extension at packages/%s/%s declares package "%s" but the directory implies "%s". An extension must own only its own package.',
                    $entry,
                    self::LOCAL_EXTENSION_REL_PATH,
                    $extension->package,
                    $expectedPackage,
                ));
            }

            $this->validateLocalExtensionGuards($extension, $global, $entry);

            $extensions[$extension->package] = $extension;

            $existingDirs = $packageSpecificCodeRoot[$extension->package]['directories'] ?? [];
            $existingFiles = $packageSpecificCodeRoot[$extension->package]['files'] ?? [];
            $packageSpecificCodeRoot[$extension->package] = [
                'directories' => array_values(array_unique(array_merge($existingDirs, $extension->topLevelDirectories))),
                'files'       => array_values(array_unique(array_merge($existingFiles, $extension->topLevelFiles))),
            ];

            foreach ($extension->pathRules as $relPath => $rule) {
                if (!$rule instanceof ModuleStructureRule) {
                    throw new \RuntimeException(sprintf(
                        'Local extension for "%s": pathRules[%s] must be a %s instance.',
                        $extension->package,
                        $relPath,
                        ModuleStructureRule::class,
                    ));
                }
                $packageScopedRules[$extension->package][$relPath] = $rule;
            }
        }

        return new ModuleStructureSpec(
            codeRootRules: $global->codeRootRules,
            packageRootRule: $global->packageRootRule,
            filePlacement: $global->filePlacement,
            packageOnlyDirectories: $global->packageOnlyDirectories,
            requiredPackageRootEntries: $global->requiredPackageRootEntries,
            forbiddenInProductionPackages: $global->forbiddenInProductionPackages,
            packageSpecificCodeRoot: $packageSpecificCodeRoot,
            packageScopedRules: $packageScopedRules,
            localExtensions: $extensions,
        );
    }

    /**
     * Hard guard rails. A local extension cannot:
     *
     *   - declare a top-level directory whose name appears in
     *     {@see ModuleStructureSpec::$forbiddenInProductionPackages}
     *     (Demo, Sandbox, Playground, Example, Sample, Fake, Experimental, …);
     *   - introduce a rule for `Domain/Contract` or `Exception` (the global
     *     `*Interface.php` / `*Exception.php` discipline must remain the
     *     single source of truth for those layers);
     *   - introduce a rule for the singular `Attribute/` layer (global
     *     spec already owns it);
     *   - declare a top-level file outside the `*.php` extension (we only
     *     allow PHP root files via this mechanism — non-PHP package metadata
     *     stays in the global packageRoot rule);
     *   - declare a top-level directory that lacks a corresponding `pathRules`
     *     entry (every authorised directory must have an explicit rule —
     *     silent skipping is forbidden, mirroring the global Phase 2 rule).
     */
    private function validateLocalExtensionGuards(
        LocalModuleStructureExtension $extension,
        ModuleStructureSpec $global,
        string $packageDirEntry,
    ): void {
        $where = sprintf('packages/%s/%s', $packageDirEntry, self::LOCAL_EXTENSION_REL_PATH);

        foreach ($extension->topLevelDirectories as $dir) {
            if ($global->isForbiddenInProductionPackages($dir)) {
                throw new \RuntimeException(sprintf(
                    '%s: forbidden override — local extension cannot allow "%s/" because that name is in the production-pollution deny-list (%s).',
                    $where,
                    $dir,
                    ModuleStructureViolation::CODE_LOCAL_EXTENSION_FORBIDDEN_OVERRIDE,
                ));
            }
            if (in_array($dir, ['Domain', 'Application', 'Configuration', 'Context', 'Update', 'Static', 'View', 'Exception', 'Attribute', 'Auth', 'Discovery', 'OpenApi', 'Pipeline'], true)) {
                throw new \RuntimeException(sprintf(
                    '%s: forbidden override — local extension cannot redeclare canonical top-level "%s/"; it is owned by the global spec (%s).',
                    $where,
                    $dir,
                    ModuleStructureViolation::CODE_LOCAL_EXTENSION_FORBIDDEN_OVERRIDE,
                ));
            }
            if (!isset($extension->pathRules[$dir])) {
                throw new \RuntimeException(sprintf(
                    '%s: invalid extension — top-level directory "%s/" is declared but no pathRules[%s] entry exists. Every authorised directory must have an explicit rule (deep_validated / opaque_internal / leaf_files_only). Diagnostic: %s.',
                    $where,
                    $dir,
                    $dir,
                    ModuleStructureViolation::CODE_LOCAL_EXTENSION_INVALID,
                ));
            }
        }

        foreach ($extension->topLevelFiles as $file) {
            if (!str_ends_with($file, '.php')) {
                throw new \RuntimeException(sprintf(
                    '%s: invalid extension — top-level file "%s" must be a *.php basename (non-PHP root files belong in the global packageRoot rule). Diagnostic: %s.',
                    $where,
                    $file,
                    ModuleStructureViolation::CODE_LOCAL_EXTENSION_INVALID,
                ));
            }
        }

        foreach (array_keys($extension->pathRules) as $relPath) {
            if (in_array($relPath, ['Domain/Contract', 'Exception', 'Attribute'], true)) {
                throw new \RuntimeException(sprintf(
                    '%s: forbidden override — local extension cannot introduce a rule for "%s/"; the global *Interface.php / *Exception.php / singular Attribute conventions are non-negotiable (%s).',
                    $where,
                    $relPath,
                    ModuleStructureViolation::CODE_LOCAL_EXTENSION_FORBIDDEN_OVERRIDE,
                ));
            }
            if (str_starts_with($relPath, 'Domain/Contract/') || str_starts_with($relPath, 'Exception/') || str_starts_with($relPath, 'Attribute/')) {
                throw new \RuntimeException(sprintf(
                    '%s: forbidden override — local extension cannot introduce a sub-rule under "%s" (the global rule owns that subtree). Diagnostic: %s.',
                    $where,
                    $relPath,
                    ModuleStructureViolation::CODE_LOCAL_EXTENSION_FORBIDDEN_OVERRIDE,
                ));
            }
        }
    }

    /**
     * Stable fingerprint of every local extension file's mtime. Cheap; one
     * directory listing + one stat per local config.
     */
    private function localExtensionFingerprint(): string
    {
        $packagesDir = $this->projectRoot . '/packages';
        if (!is_dir($packagesDir)) {
            return 'no-packages';
        }
        $parts = [];
        foreach (scandir($packagesDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $abs = $packagesDir . '/' . $entry . '/' . self::LOCAL_EXTENSION_REL_PATH;
            if (is_file($abs)) {
                $parts[] = $entry . '@' . (int) @filemtime($abs);
            }
        }
        sort($parts);
        return implode(',', $parts);
    }
}
