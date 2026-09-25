<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

/**
 * The first question the API Explorer asks: what kind of thing answers here.
 * Cases are declared in the order the Explorer shows its groups.
 */
enum RouteKind: string
{
    case Page = 'page';
    case Api = 'api';
    case Stream = 'stream';
    case GraphQl = 'graphql';
    case Internal = 'internal';

    public function label(): string
    {
        return match ($this) {
            self::Page => 'Pages',
            self::Api => 'API endpoints',
            self::Stream => 'Streams (SSE)',
            self::GraphQl => 'GraphQL',
            self::Internal => 'Dev internals',
        };
    }
}
