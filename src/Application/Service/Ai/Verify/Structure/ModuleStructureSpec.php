<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * Strict allowlist specification of the Semitexa module / package directory
 * structure. The validator answers exactly one question against this spec:
 *
 *   "Is this directory or file at this path explicitly allowed?"
 *
 * If the answer is not yes, the validator emits a `module_structure.*`
 * violation. There is no implicit allow.
 *
 * The spec covers:
 *
 *   - **codeRoot rules**: rules that apply inside the module's code root
 *     (`packages/semitexa-X/src/` for packages, `src/modules/Foo/` for
 *     application modules). The synthetic path `top_level` represents the
 *     code root itself.
 *
 *   - **packageRoot rules**: rules that apply at the package's filesystem
 *     root (`packages/semitexa-X/`) — declares the package envelope:
 *     allowed metadata files (composer.json, LICENSE, ...) and required
 *     directories (composer.json + src/).
 *
 *   - **filePlacement rules**: name-pattern → required parent path. A file
 *     whose basename matches the pattern MUST live under the required path
 *     anywhere in the module tree. The canonical example is
 *     `*Command.php` → `Application/Console/Command`. This produces the
 *     `module_structure.command_wrong_location` violation.
 *
 *   - **packageOnlyDirectories**: directory names valid in package code
 *     roots but rejected inside application modules (the inverse direction
 *     does not exist — there is no "application-only" set).
 *
 * The spec is loaded from {@see ModuleStructureSpecLoader} which reads the
 * executable PHP spec at `packages/semitexa-dev/config/module-structure.php`.
 * That file is the authoritative source — the prose document
 * `packages/semitexa-docs/docs/MODULE_STRUCTURE.md` mirrors it for human
 * readers.
 */
final readonly class ModuleStructureSpec
{
    public const TOP_LEVEL_KEY = 'top_level';

    /**
     * @param array<string, ModuleStructureRule>                                                 $codeRootRules                  keyed by module-relative path; `top_level` = code root
     * @param ModuleStructureRule                                                                $packageRootRule                what `packages/semitexa-X/` itself may contain
     * @param array<string, FilePlacementRule>                                                   $filePlacement                  keyed by basename pattern; required path inside the module
     * @param list<string>                                                                       $packageOnlyDirectories         directory names valid in packages but not in src/modules/*
     * @param list<string>                                                                       $requiredPackageRootEntries     directories/files required at package root (e.g. composer.json, src)
     * @param list<string>                                                                       $forbiddenInProductionPackages  directory names that must NEVER appear anywhere under a packages/semitexa-x tree (e.g. Demo, Sandbox, Playground). Demo/sandbox/example code belongs under src/modules, not inside production packages. Triggers {@see ModuleStructureViolation::CODE_PRODUCTION_PACKAGE_POLLUTION}.
     * @param array<string, array{directories: list<string>, files: list<string>}>               $packageSpecificCodeRoot        per-package-name additional directories and files allowed at the package's code root (`packages/semitexa-NAME/src/`). The map key is the package's short name (e.g. `core` for `packages/semitexa-core`). Used for narrow framework-only layers like `Container`, `Composer`, `Lifecycle`, `Console` (top-level), and the entry-point class files at semitexa-core's source root (`Application.php`, `Environment.php`, etc.). NOT a wildcard — every entry is named explicitly. Inside any package whose name is NOT in this map, these directories fail with `module_structure.unknown_directory`.
     * @param array<string, array<string, ModuleStructureRule>>                                  $packageScopedRules             per-package-name → module-relative path → rule. Used for content rules that apply only inside one specific package (e.g. ORM's `Adapter/`, `Query/`, `Repository/` rules belong to `semitexa-orm` only and must not leak to other packages that happen to mention those names). Populated by {@see ModuleStructureSpecLoader} from per-package `config/module-structure.php` local extensions. The validator looks up rules first in this map, then falls back to global `$codeRootRules`.
     * @param array<string, LocalModuleStructureExtension>                                       $localExtensions                per-package-name → loaded local extension; ai:ask consumes this for path explanation. Empty when no local config files exist or `$discoverLocalExtensions` was disabled.
     */
    public function __construct(
        public array $codeRootRules,
        public ModuleStructureRule $packageRootRule,
        public array $filePlacement,
        public array $packageOnlyDirectories,
        public array $requiredPackageRootEntries,
        public array $forbiddenInProductionPackages = [],
        public array $packageSpecificCodeRoot = [],
        public array $packageScopedRules = [],
        public array $localExtensions = [],
    ) {}

    public function ruleFor(string $path): ?ModuleStructureRule
    {
        return $this->codeRootRules[$path] ?? null;
    }

    /**
     * Like {@see ruleFor} but consults the per-package scoped map first. The
     * scoped map holds rules contributed by package-local extensions
     * (`packages/<package>/config/module-structure.php`); they MUST NOT leak
     * to other packages, so the global `$codeRootRules` is only consulted as
     * a fallback. Pass `null` for $packageName to force global-only lookup.
     */
    public function ruleForInPackage(?string $packageName, string $path): ?ModuleStructureRule
    {
        if ($packageName !== null && isset($this->packageScopedRules[$packageName][$path])) {
            return $this->packageScopedRules[$packageName][$path];
        }
        return $this->codeRootRules[$path] ?? null;
    }

    public function isPathDeclared(string $path): bool
    {
        return isset($this->codeRootRules[$path]);
    }

    public function isPathDeclaredInPackage(?string $packageName, string $path): bool
    {
        if ($packageName !== null && isset($this->packageScopedRules[$packageName][$path])) {
            return true;
        }
        return isset($this->codeRootRules[$path]);
    }

    public function localExtensionFor(string $packageName): ?LocalModuleStructureExtension
    {
        return $this->localExtensions[$packageName] ?? null;
    }

    public function isPackageOnlyDirectory(string $name): bool
    {
        return in_array($name, $this->packageOnlyDirectories, true);
    }

    /**
     * Spec paths declared as vendor bundles, e.g. `Application/Static/vendor`.
     *
     * A scan that classifies contents has to stop at these: what is inside
     * belongs to a third party, and our names for things are not its problem.
     *
     * @return list<string>
     */
    public function vendorBundlePaths(): array
    {
        $paths = [];
        foreach ($this->codeRootRules as $path => $rule) {
            if ($rule->isVendorBundle()) {
                $paths[] = (string) $path;
            }
        }

        return $paths;
    }

    public function isForbiddenInProductionPackages(string $name): bool
    {
        // Case-insensitive match: `Demo`, `demo`, `DEMO` all read alike.
        $needle = strtolower($name);
        foreach ($this->forbiddenInProductionPackages as $forbidden) {
            if (strtolower($forbidden) === $needle) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string> additional directory names allowed at this package's code root
     */
    public function packageSpecificDirectories(string $packageName): array
    {
        return $this->packageSpecificCodeRoot[$packageName]['directories'] ?? [];
    }

    /**
     * @return list<string> additional file basenames allowed at this package's code root
     */
    public function packageSpecificFiles(string $packageName): array
    {
        return $this->packageSpecificCodeRoot[$packageName]['files'] ?? [];
    }
}
