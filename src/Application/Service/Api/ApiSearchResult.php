<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

final readonly class ApiSearchResult
{
    /**
     * @param list<ApiHit> $hits   Best first, at most the search limit.
     * @param list<string> $unread Classes that matched the text but could not be
     *                             loaded, so their members were not searched.
     */
    public function __construct(
        public array $hits,
        public int $total,
        public array $unread,
        /** No member matched every word; these matched most of them. */
        public bool $partial = false,
    ) {}
}
