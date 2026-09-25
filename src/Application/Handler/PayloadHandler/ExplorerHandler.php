<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ExplorerPayload;
use Semitexa\Dev\Application\Service\Explorer\ExplorerHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Serves the API Explorer page. 404 when the Observatory gate refuses.
 */
#[AsPayloadHandler(payload: ExplorerPayload::class, resource: ResourceResponse::class)]
final class ExplorerHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected ExplorerHtmlRenderer $renderer;

    public function handle(ExplorerPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent($this->renderer->render());
    }
}
