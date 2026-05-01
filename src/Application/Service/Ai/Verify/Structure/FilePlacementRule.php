<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Structure;

/**
 * "A file whose basename matches `pattern` is allowed inside the module only
 * if it lives under `requiredPath` (or a feature-grouping subfolder of it)."
 *
 * Used by {@see ModuleStructureValidator} to enforce strong file-placement
 * rules that are independent of the per-directory allowlist. The canonical
 * case is console commands: any `*Command.php` outside
 * `Application/Console/Command/` triggers
 * `module_structure.command_wrong_location` regardless of how that other
 * directory is otherwise governed.
 *
 * `pattern` is a PCRE expression (with delimiters) matched against the
 * file's basename — e.g. `'/Command\.php$/'` for "any class file whose
 * basename ends in Command.php".
 */
final readonly class FilePlacementRule
{
    /**
     * @param string       $code                              `module_structure.X` violation code emitted on miss
     * @param string       $pattern                           PCRE matched against basename
     * @param string       $requiredPath                      module-relative path where the file MUST live
     * @param string       $description                       short human-readable purpose ("console command")
     * @param list<string> $exemptUnderPaths                  module-relative paths whose contents bypass this placement rule entirely. Use for legitimate cross-cutting layers that share a basename pattern with the placement target — e.g. `Domain/Command/` holds CQRS-style **domain command DTOs** (final readonly classes), not console commands; their basenames legitimately end in `Command.php` but they belong in `Domain/Command/`, not `Application/Console/Command/`.
     * @param list<string> $forbiddenContentPatternsUnderExempt PCRE patterns matched against the file's *content* when, and only when, the file lives under one of `$exemptUnderPaths`. If any pattern matches, the exemption is REVOKED and the placement violation fires anyway. This closes the loophole where a real executable console command is hidden under an exempt path (e.g. someone drops `class FooCommand extends BaseCommand` into `Domain/Command/`).
     */
    public function __construct(
        public string $code,
        public string $pattern,
        public string $requiredPath,
        public string $description,
        public array $exemptUnderPaths = [],
        public array $forbiddenContentPatternsUnderExempt = [],
    ) {}

    public function matches(string $basename): bool
    {
        return preg_match($this->pattern, $basename) === 1;
    }

    /**
     * True when `$pathInsideModule` is at, or below, `$this->requiredPath`.
     * The file is in the canonical location for this placement rule.
     */
    public function isUnderRequiredPath(string $pathInsideModule): bool
    {
        return $this->isAtOrUnder($pathInsideModule, $this->requiredPath);
    }

    /**
     * True when `$pathInsideModule` is at, or below, any path declared in
     * {@see self::$exemptUnderPaths}. Path-only — does NOT account for
     * content-based exemption revocation; use
     * {@see self::contentRevokesExemption()} together with this.
     */
    public function isUnderExemptPath(string $pathInsideModule): bool
    {
        foreach ($this->exemptUnderPaths as $exempt) {
            if ($this->isAtOrUnder($pathInsideModule, $exempt)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Back-compat helper: combines {@see self::isUnderRequiredPath()} and
     * {@see self::isUnderExemptPath()} for callers that only care that the
     * file is "in some accepted location" — without considering whether the
     * exemption is actually valid for this file's content.
     */
    public function pathSatisfies(string $pathInsideModule): bool
    {
        return $this->isUnderRequiredPath($pathInsideModule)
            || $this->isUnderExemptPath($pathInsideModule);
    }

    /**
     * True when the file content matches any forbidden pattern, signalling
     * that the file is actually an executable instance of the placement
     * target (e.g. an `#[AsCommand]` console command) and therefore must
     * NOT be allowed to hide under an exempt path. Caller is expected to
     * invoke this only when {@see self::isUnderExemptPath()} is true.
     */
    public function contentRevokesExemption(?string $fileContent): bool
    {
        if ($fileContent === null || $this->forbiddenContentPatternsUnderExempt === []) {
            return false;
        }
        foreach ($this->forbiddenContentPatternsUnderExempt as $pattern) {
            if (preg_match($pattern, $fileContent) === 1) {
                return true;
            }
        }
        return false;
    }

    private function isAtOrUnder(string $path, string $target): bool
    {
        $target = trim($target, '/');
        $under  = trim($path, '/');
        if ($under === $target) {
            return true;
        }
        return str_starts_with($under, $target . '/');
    }
}

