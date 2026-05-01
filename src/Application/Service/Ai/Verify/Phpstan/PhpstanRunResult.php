<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\Phpstan;

/**
 * Outcome of one {@see PhpstanRunner} invocation.
 *
 * `diagnostics` carries the array form of every PHPStan message converted to
 * the NDJSON shape that `ai:verify` emits — each row keeps the original
 * PHPStan `identifier` so downstream consumers can route on the same key
 * the project's `composer phpstan` run uses.
 */
final readonly class PhpstanRunResult
{
    public const STATUS_PASS    = 'pass';
    public const STATUS_FAIL    = 'fail';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * @param list<array<string, mixed>> $diagnostics
     */
    public function __construct(
        public string $status,
        public array $diagnostics,
        public string $rawSignal,
    ) {}
}
