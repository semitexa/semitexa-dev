<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\TracePayload;
use Semitexa\Dev\Application\Service\Trace\TraceHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\TraceReader;

/**
 * Serves the recorded traces at `/__trace`.
 *
 * Answers 404 rather than 403 outside dev. A "forbidden" would confirm the route
 * exists, and there is nothing here worth telling a stranger about.
 */
#[AsPayloadHandler(payload: TracePayload::class, resource: ResourceResponse::class)]
final class TraceHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected TraceReader $reader;

    #[InjectAsReadonly]
    protected TraceHtmlRenderer $renderer;

    public function handle(TracePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->reader->isEnabled()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $html = $payload->file !== ''
            ? $this->renderOne($payload->file)
            : $this->renderer->renderList($this->reader->list(), $this->reader->dir());

        return $resource
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            // A trace is a point-in-time recording and the list changes with every
            // request a developer makes, so a cached copy is always the wrong one.
            ->setHeader('Cache-Control', 'no-store')
            ->setContent($html);
    }

    private function renderOne(string $file): string
    {
        $trace = $this->reader->read($file);

        if ($trace === null) {
            return $this->renderer->renderList($this->reader->list(), $this->reader->dir());
        }

        return $this->renderer->renderTrace($trace);
    }
}
