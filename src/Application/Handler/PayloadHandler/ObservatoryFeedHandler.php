<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryFeedPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;

/**
 * The JSON snapshot behind `/__observatory` — live processes folded from the
 * Observatory journal. One second of polling reads at most a few MB of NDJSON
 * tail; no state is held between polls, so a worker restart costs nothing.
 */
#[AsPayloadHandler(payload: ObservatoryFeedPayload::class, resource: ResourceResponse::class)]
final class ObservatoryFeedHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryReader $reader;

    public function handle(ObservatoryFeedPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->reader->isEnabled()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($this->reader->snapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
