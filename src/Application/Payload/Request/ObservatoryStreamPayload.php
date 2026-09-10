<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Attribute\SseGateModel;
use Semitexa\Core\Attribute\TransportType;
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
 *
 * Declared `transport: TransportType::Sse`, which is what it is: a long-lived
 * GET the server holds open and pushes on. The declaration is load-bearing —
 * the framework's route smoke walks every route asserting no 5xx, and without
 * it this endpoint answered 503 there ("no live Swoole connection to hold
 * open"), because a synthetic request has no socket. Routes declaring the SSE
 * transport are skipped from that walk for exactly this reason.
 *
 * The gate model is `ChannelToken`: public at the routing layer, gated inside
 * the handler by {@see \Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate}
 * — open in dev, and elsewhere the `X-Observatory-Token` header or a direct
 * loopback peer. No subject is re-authorized per tick, so `Subject` would be a
 * false promise (and the boot guard rejects it on a public route), and no
 * bearer session is involved either.
 */
#[AsPublicPayload(
    path: '/__observatory/stream',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
    transport: TransportType::Sse,
    sseGateModel: SseGateModel::ChannelToken,
)]
final class ObservatoryStreamPayload
{
    public string $after = '';

    public function setAfter(mixed $value): void
    {
        $this->after = is_string($value) ? $value : '';
    }
}
