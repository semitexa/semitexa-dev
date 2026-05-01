<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Convention;

/**
 * One injected property pattern observed across handlers in a module.
 *
 * `frequency` is the count of distinct handler classes that inject this exact
 * type. The conventional injections are those with frequency ≥ 2 (one
 * occurrence is just a single handler, not a convention).
 */
final readonly class HandlerInjection
{
    public function __construct(
        public string $type,
        public string $shortType,
        public string $attribute,
        public string $propertyName,
        public int $frequency,
    ) {}
}
