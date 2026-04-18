<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Similarity;

/**
 * Describes what a scaffolder is about to create, in a shape the detector
 * can compare against the {@see SimilarityIndex}.
 *
 * Fields are all plain strings — callers resolve names before calling.
 * `extras` carries the same keys the index stores for that kind:
 *   - payload:  `route_path`, `route_method`, `resource_class`
 *   - handler:  `payload_fqcn`, `resource_fqcn`
 *   - listener: `event_fqcn`
 */
final readonly class DuplicateQuery
{
    /**
     * @param array<string, string> $extras
     */
    public function __construct(
        public string $kind,
        public string $module,
        public string $className,
        public string $fqcn,
        public string $relativePath,
        public array $extras = [],
    ) {}
}
