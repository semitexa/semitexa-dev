<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ExplorerTracePayload;
use Semitexa\Dev\Application\Service\Explorer\TraceLocator;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Answers `{"file": "<trace file>"|null}` for an Explorer marker. A null means
 * "not flushed yet" as often as "never traced"; the browser asks a few times.
 */
#[AsPayloadHandler(payload: ExplorerTracePayload::class, resource: ResourceResponse::class)]
final class ExplorerTraceHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ExplorerTracePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode(['file' => TraceLocator::find($payload->getToken())], JSON_UNESCAPED_SLASHES));
    }
}
