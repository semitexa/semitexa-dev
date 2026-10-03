<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * One violation produced by the strict allowlist {@see ModuleStructureValidator}.
 *
 * Issue codes (constant `CODE_*`) are stable, AI-facing strings. Each
 * violation also carries `expected` and `actual` so the consumer can fix
 * without guessing what the rule is. Designed to be NDJSON-serialised
 * verbatim into `ai:verify` output.
 */
final readonly class ModuleStructureViolation
{
    public const SEVERITY_ERROR = 'error';

    public const CODE_UNKNOWN_DIRECTORY            = 'module_structure.unknown_directory';
    public const CODE_INVALID_LAYER                = 'module_structure.invalid_layer';
    public const CODE_INVALID_LOCATION             = 'module_structure.invalid_location';
    public const CODE_INVALID_NAMESPACE            = 'module_structure.invalid_namespace';
    public const CODE_UNDECLARED_PATH              = 'module_structure.undeclared_path';
    public const CODE_COMMAND_WRONG_LOCATION       = 'module_structure.command_wrong_location';
    public const CODE_INVALID_ROOT_FILE            = 'module_structure.invalid_root_file';
    public const CODE_MISSING_REQUIRED_PATH        = 'module_structure.missing_required_path';
    /** Demo / sandbox / playground / example / fake / experimental folder appears inside a production package. */
    public const CODE_PRODUCTION_PACKAGE_POLLUTION = 'module_structure.production_package_pollution';
    /** A directory is permitted at the top level by the package-specific allowlist but lacks an explicit child rule (deep_validated / opaque_internal / leaf_files_only) — silent skipping is forbidden. */
    public const CODE_OPAQUE_MARKER_REQUIRED       = 'module_structure.opaque_marker_required';
    /** A package-local module-structure extension file is malformed: must `return` a {@see LocalModuleStructureExtension} and every declared top-level directory must have a path rule. */
    public const CODE_LOCAL_EXTENSION_INVALID         = 'module_structure.local_extension_invalid';
    /** A package-local module-structure extension tries to weaken or override a global forbidden / naming / production-pollution rule. */
    public const CODE_LOCAL_EXTENSION_FORBIDDEN_OVERRIDE = 'module_structure.local_extension_forbidden_override';
    /** A package-local rule shadows a global rule for the same path with a DIFFERENT contract — the global rule (and everything documenting/pinning it) is silently dead for that package. Align both to one contract in the same change. */
    public const CODE_LOCAL_RULE_DIVERGENCE           = 'module_structure.local_rule_divergence';

    public const DOC_REF = 'packages/semitexa-docs/docs/MODULE_STRUCTURE.md';

    /**
     * Why each check exists, and what taught us — printed with every violation,
     * because the moment an agent is about to argue with a structure rule is the
     * moment the history is worth reading. Same shape as the PHPStan rules'
     * RATIONALE: a cause, then "Learned <date>: ..." or "no incident on record".
     */
    public const RATIONALES = [
        self::CODE_UNKNOWN_DIRECTORY => 'Why: the module tree is a strict allowlist, so a directory nobody declared is the start of a second convention that every reader and generator then has to guess at. Policy since 2026-04-30 (strict allowlist); no incident on record.',
        self::CODE_INVALID_LAYER => 'Why: a layer valid at a package code root (Attribute, Auth, Discovery, OpenApi, Pipeline) is framework plumbing; inside an application module it puts framework-shaped code where module code is expected. Policy since 2026-04-30; no incident on record.',
        self::CODE_INVALID_LOCATION => 'Why: a file whose name declares its kind (*Command, *Mapper, *Repository) has exactly one home, so generators, discovery and readers find it without searching. Policy since 2026-04-30; no incident on record.',
        self::CODE_INVALID_NAMESPACE => 'Why: a namespace that does not match the path breaks PSR-4 autoloading of the class, which shows up only when something first references it. Policy since 2026-04-30; no incident on record.',
        self::CODE_UNDECLARED_PATH => 'Why: a path with no rule would be skipped, and a skipped path is validated by nothing while looking validated. Policy since 2026-04-30 (no implicit allow); no incident on record.',
        self::CODE_COMMAND_WRONG_LOCATION => 'Why: #[AsCommand] classes are application orchestration and live under Application/Console/Command/ in packages and modules alike. Learned 2026-04-30: an attempt to move them to bare src/Console/Command/ was rejected by the operator; one location, one mental model.',
        self::CODE_INVALID_ROOT_FILE => 'Why: the package and code roots hold only the files the envelope declares; anything else at the root is a file with no layer and no owner. Policy since 2026-04-30; no incident on record.',
        self::CODE_MISSING_REQUIRED_PATH => 'Why: composer.json and src/ are what makes a directory a package; without them the installer and the autoloader do not see it. Policy since 2026-04-30; no incident on record.',
        self::CODE_PRODUCTION_PACKAGE_POLLUTION => 'Why: a production package ships to every consumer, so Demo, Sandbox, Fake or Experimental code inside it is installed and discovered in their applications too. Local demos belong under src/modules/. Policy since 2026-04-30; no incident on record.',
        self::CODE_OPAQUE_MARKER_REQUIRED => 'Why: a directory allowed without a depth rule would be skipped silently, so it must say how deep it is checked (deep_validated, opaque_internal or leaf_files_only). Policy since 2026-04-30 (Phase 2 depth modes); no incident on record.',
        self::CODE_LOCAL_EXTENSION_INVALID => 'Why: a malformed package extension would be dropped or half-applied, and the package would be validated against rules nobody wrote. Policy since 2026-05-01 (local extensions); no incident on record.',
        self::CODE_LOCAL_EXTENSION_FORBIDDEN_OVERRIDE => 'Why: a package may add top-level directories, never redefine the canonical ones; otherwise each package grows its own idea of what Application/ or Domain/ means. Policy since 2026-05-01; no incident on record.',
        self::CODE_LOCAL_RULE_DIVERGENCE => 'Why: a package-local rule silently replaces the global rule for the same path, so the global rule, its docs row and its tests keep describing behaviour that no longer runs. Learned 2026-07-05: that drift kept the structure suite red for weeks, and the first run of this check found five more divergences in three packages.',
    ];

    public function __construct(
        public string $code,
        public string $module,
        public string $path,
        public string $message,
        public string $expected,
        public string $actual,
        public string $suggestedFix,
        public ?string $namespace = null,
        public string $severity = self::SEVERITY_ERROR,
        public string $docRef = self::DOC_REF,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'check'         => 'module_structure',
            'severity'      => $this->severity,
            'rule'          => $this->code,        // legacy field name kept for NDJSON consumers
            'code'          => $this->code,
            'module'        => $this->module,
            'path'          => $this->path,
            'namespace'     => $this->namespace,
            'message'       => $this->message,
            'expected'      => $this->expected,
            'actual'        => $this->actual,
            'doc_ref'       => $this->docRef,
            'suggested_fix' => $this->suggestedFix,
            'rationale'     => $this->rationale(),
        ];
    }

    public function rationale(): string
    {
        return self::RATIONALES[$this->code] ?? '';
    }

    /** The rationale as the tail of a one-line signal, or nothing. */
    public function whyClause(): string
    {
        $why = $this->rationale();

        return $why === '' ? '' : ' — ' . $why;
    }
}
