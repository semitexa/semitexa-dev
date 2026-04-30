<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify\Structure;

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

    public const DOC_REF = 'packages/semitexa-docs/docs/MODULE_STRUCTURE.md';

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
        ];
    }
}
