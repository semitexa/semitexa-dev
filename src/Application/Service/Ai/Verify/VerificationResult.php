<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify;

/**
 * Per-target outcome. `signal` holds the last non-empty line of the target's
 * output — the same trick {@see \Semitexa\Dev\Application\Service\Generation\Verifier\PostWriteLinter}
 * uses to compress a lint's verdict into one readable line.
 */
final readonly class VerificationResult
{
    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param list<array<string, mixed>> $diagnostics structured per-target findings
     *     (currently used by module_structure to emit one entry per
     *     {@see \Semitexa\Dev\Application\Service\Ai\Verify\Structure\ModuleStructureViolation})
     */
    public function __construct(
        public VerificationTarget $target,
        public string $status,
        public int $exitCode,
        public string $signal,
        public array $diagnostics = [],
    ) {}
}
