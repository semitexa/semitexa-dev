<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryTimelinePayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;
use Semitexa\Dev\Application\Service\Trace\PageTimelineHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\PageTimelineReader;

/**
 * Serves the page timelines. 404 outside development: the timeline is not
 * recorded there, and nothing here is worth telling a stranger about.
 */
#[AsPayloadHandler(payload: ObservatoryTimelinePayload::class, resource: ResourceResponse::class)]
final class ObservatoryTimelineHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected PageTimelineReader $reader;

    #[InjectAsReadonly]
    protected PageTimelineHtmlRenderer $renderer;

    public function handle(ObservatoryTimelinePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!ObservatoryMode::full()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $html = $payload->page === ''
            ? $this->renderer->renderPages($this->reader->pages(50))
            : $this->renderer->renderPage($payload->page, $this->reader->events($payload->page, 2000));

        return $resource
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent($html);
    }
}
