<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Verify\Structure;

/**
 * One module identified by {@see AffectedModuleDetector}. The structure
 * validator scans `relativePath` recursively.
 */
final readonly class DetectedModule
{
    public const KIND_APPLICATION = 'application';
    public const KIND_PACKAGE     = 'package';

    public function __construct(
        public string $name,
        public string $relativePath,
        public string $kind,
    ) {}

    public function isApplicationModule(): bool
    {
        return $this->kind === self::KIND_APPLICATION;
    }

    public function isPackageModule(): bool
    {
        return $this->kind === self::KIND_PACKAGE;
    }
}
