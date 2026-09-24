<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Payload\Request\QualityPayload;
use Semitexa\Dev\Application\Service\Quality\QualityHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;

/**
 * Serves the quality ledger at `/__quality`.
 *
 * 404 outside dev, not 403 — the same reasoning as /__trace: a "forbidden"
 * confirms the route exists, and a ledger of the codebase's weak spots is
 * nothing to show a stranger.
 */
#[AsPayloadHandler(payload: QualityPayload::class, resource: ResourceResponse::class)]
final class QualityHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected QualityHtmlRenderer $renderer;

    public function __construct()
    {
    }

    public function handle(QualityPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!ObservatoryMode::full()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', 'text/html; charset=utf-8')
            // The ledger changes with every record; a cached copy is the wrong one.
            ->setHeader('Cache-Control', 'no-store')
            ->setContent($this->renderer->render(ProjectRoot::get()));
    }
}
