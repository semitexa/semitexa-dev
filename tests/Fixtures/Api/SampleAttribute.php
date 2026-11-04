<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Fixtures\Api;

use Attribute;

/**
 * Marks a sample.
 *
 * @api
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class SampleAttribute
{
    public function __construct(
        public SampleMode $mode,
        public SampleContract $contract,
        public ?int $length = null,
        public bool $nullable = false,
        /** Overrides the label shown to the visitor. */
        public ?string $label = null,
    ) {}
}
