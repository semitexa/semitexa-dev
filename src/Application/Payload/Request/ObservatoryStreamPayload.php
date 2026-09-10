<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The journal as a Server-Sent Events stream, for the live panel.
 *
 * Same rows as `/__observatory/feed?stream=1`, pushed instead of polled: one
 * `batch` event per tick with the rows appended since the cursor, and the
 * next cursor to resume from after a reconnect (`?after=`). The connection
 * is a real process of this system — it appears in the panel's LIVE ring
 * like any other SSE session — which is the whole reason it exists: the
 * panel watching itself over the same kind of connection it draws.
 */
#[AsPublicPayload(
    path: '/__observatory/stream',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryStreamPayload
{
    public string $after = '';

    public function setAfter(mixed $value): void
    {
        $this->after = is_string($value) ? $value : '';
    }
}
