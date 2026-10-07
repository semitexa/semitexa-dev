<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * A page's live timeline, at `/__observatory/timeline` (development only; the
 * handler answers 404 elsewhere, as `/__trace` does): the pages seen lately,
 * and one page's UI events, stream frames, subscriptions and re-runs.
 */
#[AsPublicPayload(
    path: '/__observatory/timeline',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryTimelinePayload
{
    /** The page's KISS session id. Empty shows the list. */
    public string $page = '';

    public function setPage(mixed $value): void
    {
        $this->page = is_string($value) ? trim($value) : '';
    }
}
