<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * One node in a {@see ModuleStructureSpec} — declares what is **explicitly
 * allowed** at one path inside a Semitexa module / package.
 *
 * The validator is strict allowlist: anything not declared here fails. A path
 * that does not have a `ModuleStructureRule` at all is "undeclared" and any
 * directory or file at that path is rejected with
 * `module_structure.undeclared_path`.
 *
 * Phase 2 introduces an explicit **validation mode** per rule:
 *
 *   - `MODE_DEEP_VALIDATED` (default) — children are validated against this
 *     rule's `allowedDirectories` / `allowedFiles` / `allowedFilePatterns`
 *     (with the optional `excludedFilePatterns` deny-list applied last).
 *     `allowFeatureGrouping` and `allowAnyFile` keep their existing meaning.
 *
 *   - `MODE_OPAQUE_INTERNAL` — the validator does NOT scan this directory's
 *     contents at all. Used as an **explicit** opt-out for complex framework
 *     internals (typically inside `semitexa-core`) where deep child rules
 *     have not yet been authored. Every opaque entry MUST carry a
 *     human-readable `opaqueReason`, an `opaqueOwner`, and an `opaqueTodo`
 *     describing the path to deep validation. Opaque is NOT a wildcard —
 *     it is a tracked deferred rule.
 *
 *   - `MODE_VENDOR_BUNDLE` — this path holds ONE DIRECTORY PER third-party
 *     bundle. Each bundle directory is accepted whatever it is called and its
 *     contents are not classified, because they belong to their author. Files
 *     directly at this level are still rejected: a bundle is a directory, and
 *     letting loose files sit beside them would make one root a shared dumping
 *     ground for several bundles. Distinct from `MODE_OPAQUE_INTERNAL`, which
 *     means "deep validation is owed and tracked": a vendored bundle is never
 *     going to be deep-validated, because its layout is not ours to decide.
 *     A bundle like Leaflet is one indivisible thing — `leaflet.js`,
 *     `leaflet.css` and `images/*.png` together, with the CSS referencing the
 *     images relative to itself — so splitting it across `css/`, `js/` and
 *     `img/` breaks the vendored file's own URLs, and filing it whole under one
 *     type directory is a lie the runtime never asked for: `StaticAssetHandler`
 *     and `ModuleAssetResolver` both dispatch on file extension, never on the
 *     directory a file sits in.
 *
 *   - `MODE_LEAF_FILES_ONLY` — the rule accepts files (filtered by
 *     `allowedFiles` / `allowedFilePatterns` / `allowAnyFile`) but rejects
 *     EVERY direct subdirectory with `module_structure.unknown_directory`.
 *     Used for leaves where feature subgrouping is architecturally wrong
 *     (e.g. a leaf that holds exactly one kind of file).
 *
 * `path` is module-relative, with `top_level` as the synthetic name for the
 * module root (`packages/semitexa-X/src/` for packages,
 * `src/modules/Foo/` for application modules).
 */
final readonly class ModuleStructureRule
{
    public const MODE_DEEP_VALIDATED  = 'deep_validated';
    public const MODE_OPAQUE_INTERNAL = 'opaque_internal';
    public const MODE_LEAF_FILES_ONLY = 'leaf_files_only';
    public const MODE_VENDOR_BUNDLE   = 'vendor_bundle';

    /**
     * @param string                $path                 module-relative path; `top_level` for the module root
     * @param list<string>          $allowedDirectories   exact directory names allowed as direct children of `path`
     * @param list<string>          $allowedFiles         exact file basenames allowed as direct children of `path`
     * @param list<string>          $allowedFilePatterns  PCRE patterns matching allowed file basenames
     * @param list<string>          $excludedFilePatterns PCRE patterns matching basenames that are NEVER allowed at this path, even when `allowAnyFile` or `allowedFilePatterns` would otherwise match. Use to encode "this filename family belongs in a different layer" — e.g. `*Resource.php` is excluded from `Domain/Model/` because persistence resource models live in `Application/Db/<Adapter>/Model/`.
     * @param bool                  $allowFeatureGrouping if true, ANY direct child directory name is allowed (feature subfolders such as `Customer/`, `Order/`)
     * @param bool                  $allowAnyFile         if true, ANY file (subject to `excludedFilePatterns`) is allowed at this path
     * @param string                $mode                 one of MODE_DEEP_VALIDATED / MODE_OPAQUE_INTERNAL / MODE_LEAF_FILES_ONLY / MODE_VENDOR_BUNDLE
     * @param string|null           $opaqueReason         required when `$mode === MODE_OPAQUE_INTERNAL`: why this directory is not yet deep-validated
     * @param string|null           $opaqueOwner          required when opaque: who owns the path forward to deep validation
     * @param string|null           $opaqueTodo           required when opaque: concrete steps needed to remove the opacity
     * @param string|null           $rationale            human-readable explanation of why this path exists (always recommended)
     */
    public function __construct(
        public string $path,
        public array $allowedDirectories = [],
        public array $allowedFiles = [],
        public array $allowedFilePatterns = [],
        public array $excludedFilePatterns = [],
        public bool $allowFeatureGrouping = false,
        public bool $allowAnyFile = false,
        public string $mode = self::MODE_DEEP_VALIDATED,
        public ?string $opaqueReason = null,
        public ?string $opaqueOwner = null,
        public ?string $opaqueTodo = null,
        public ?string $rationale = null,
    ) {}

    /**
     * Whether two rules enforce the SAME contract — everything that decides
     * accept/reject (mode, flags, directory and file allow/deny sets, order-
     * insensitive) — ignoring prose (`rationale`, opaque metadata) and `path`.
     *
     * Used by the local-vs-global shadowing guard: a package-local rule that
     * shadows a global rule for the same path must be contract-identical,
     * otherwise the global rule (and the docs/tests pinning it) is silently
     * dead for that package.
     */
    public function contractEquals(self $other): bool
    {
        $sorted = static function (array $values): array {
            sort($values);
            return $values;
        };

        return $this->mode === $other->mode
            && $this->allowFeatureGrouping === $other->allowFeatureGrouping
            && $this->allowAnyFile === $other->allowAnyFile
            && $sorted($this->allowedDirectories) === $sorted($other->allowedDirectories)
            && $sorted($this->allowedFiles) === $sorted($other->allowedFiles)
            && $sorted($this->allowedFilePatterns) === $sorted($other->allowedFilePatterns)
            && $sorted($this->excludedFilePatterns) === $sorted($other->excludedFilePatterns);
    }

    public function permitsDirectory(string $name): bool
    {
        if ($this->mode === self::MODE_LEAF_FILES_ONLY) {
            return false;
        }
        if ($this->allowFeatureGrouping) {
            return true;
        }
        return in_array($name, $this->allowedDirectories, true);
    }

    public function permitsFile(string $basename): bool
    {
        // Excluded patterns are absolute: even allowAnyFile / a matching
        // allowedFilePattern cannot rescue a basename listed here.
        foreach ($this->excludedFilePatterns as $excluded) {
            if (preg_match($excluded, $basename) === 1) {
                return false;
            }
        }
        if ($this->allowAnyFile) {
            return true;
        }
        if (in_array($basename, $this->allowedFiles, true)) {
            return true;
        }
        foreach ($this->allowedFilePatterns as $pattern) {
            if (preg_match($pattern, $basename) === 1) {
                return true;
            }
        }
        return false;
    }

    public function isOpaqueInternal(): bool
    {
        return $this->mode === self::MODE_OPAQUE_INTERNAL;
    }

    /** A third-party subtree, kept as shipped and not classified by us. */
    public function isVendorBundle(): bool
    {
        return $this->mode === self::MODE_VENDOR_BUNDLE;
    }

    /** Modes whose contents the validator does not descend into at all. */
    public function skipsChildScan(): bool
    {
        return $this->isOpaqueInternal();
    }

    public function isLeafFilesOnly(): bool
    {
        return $this->mode === self::MODE_LEAF_FILES_ONLY;
    }
}
