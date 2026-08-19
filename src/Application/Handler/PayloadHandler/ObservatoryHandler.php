<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;

/**
 * Serves the live process panel at `/__observatory`.
 *
 * 404 rather than 403 outside dev, same as the trace viewer: a "forbidden"
 * would confirm the route exists, and there is nothing here worth telling a
 * stranger about.
 */
#[AsPayloadHandler(payload: ObservatoryPayload::class, resource: ResourceResponse::class)]
final class ObservatoryHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryReader $reader;

    #[InjectAsReadonly]
    protected ObservatoryHtmlRenderer $renderer;

    public function handle(ObservatoryPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->reader->isEnabled()) {
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
