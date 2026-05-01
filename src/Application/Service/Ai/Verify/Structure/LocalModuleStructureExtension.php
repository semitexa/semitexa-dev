<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * One package-local additive extension to the global ModuleStructureSpec.
 *
 * Loaded from `packages/<package>/config/module-structure.php`. Authorises
 * package-only additional top-level directories and root files plus their
 * validation rules. Strictly additive: cannot override or weaken any global
 * forbidden / naming / production-pollution rule (enforced by
 * {@see ModuleStructureSpecLoader} at merge time).
 *
 * The companion file `packages/<package>/docs/MODULE_STRUCTURE.md` is the
 * human explanation. Only this executable extension is consulted by ai:verify
 * — Markdown alone is never executable validation.
 */
final readonly class LocalModuleStructureExtension
{
    /**
     * @param string                                $package             package short name (no `semitexa-` prefix), e.g. `orm` for `packages/semitexa-orm`
     * @param list<string>                          $topLevelDirectories directory names additionally allowed at this package's `src/` (each MUST appear as a key in `$pathRules`)
     * @param list<string>                          $topLevelFiles       file basenames additionally allowed at this package's `src/` root
     * @param array<string, ModuleStructureRule>    $pathRules           map of module-relative path → rule for the contents of each declared top-level directory; required for every entry in `$topLevelDirectories`
     * @param string|null                           $docPath             repo-relative path to the human docs (`packages/<package>/docs/MODULE_STRUCTURE.md`); ai:ask references it
     * @param string|null                           $reason              one-line summary of why this package needs the extension
     */
    public function __construct(
        public string $package,
        public array $topLevelDirectories = [],
        public array $topLevelFiles = [],
        public array $pathRules = [],
        public ?string $docPath = null,
        public ?string $reason = null,
    ) {}
}
