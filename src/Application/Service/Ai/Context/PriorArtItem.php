<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Context;

final readonly class PriorArtItem
{
    public function __construct(
        public string $path,
        public string $module,
        public string $type,
        public int $score,
        public string $why,
    ) {}
}
