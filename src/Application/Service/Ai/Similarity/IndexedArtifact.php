<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Similarity;

/**
 * One artifact the similarity index knows about. A very narrow projection —
 * just the fields needed by {@see DuplicateDetector} to spot collisions.
 *
 * `extras` carries kind-specific metadata:
 *  - payload: `route_path`, `route_method`, `payload_class`, `resource_class`
 *  - handler: `payload_class`, `resource_class`
 *  - listener: `event_fqcn`
 */
final readonly class IndexedArtifact
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
