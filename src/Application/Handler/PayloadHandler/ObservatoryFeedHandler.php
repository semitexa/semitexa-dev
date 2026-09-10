<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryFeedPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\CoroutineSnapshot;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;

/**
 * The JSON behind `/__observatory`: the folded snapshot (live + just finished)
 * by default, or with `?stream=1&after=<cursor>` the journal itself as a
 * delta stream, which is what lets the panel animate each request as it
 * lands instead of re-reading the tail once a second. No state is held
 * between polls either way — the file is the state, the cursor is a byte
 * offset into it — so a worker restart costs nothing.
 */
#[AsPayloadHandler(payload: ObservatoryFeedPayload::class, resource: ResourceResponse::class)]
final class ObservatoryFeedHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryReader $reader;

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ObservatoryFeedPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        if ($payload->stream) {
            // The worker answering the panel publishes its own coroutines too;
            // with several workers each poll lands on a different one, so the
            // picture fills in within a few polls.
            CoroutineSnapshot::maybeWrite();
        }
        $body = $payload->stream
            ? $this->reader->stream($payload->after !== '' ? $payload->after : null)
            : $this->reader->snapshot();

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
