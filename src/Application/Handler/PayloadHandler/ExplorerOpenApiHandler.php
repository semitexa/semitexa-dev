<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ExplorerOpenApiPayload;
use Semitexa\Dev\Application\Service\Explorer\OpenApiExporter;
use Semitexa\Dev\Application\Service\Explorer\RouteKind;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Serves the all-routes OpenAPI document. 404 when the Observatory gate refuses.
 */
#[AsPayloadHandler(payload: ExplorerOpenApiPayload::class, resource: ResourceResponse::class)]
final class ExplorerOpenApiHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected OpenApiExporter $exporter;

    public function handle(ExplorerOpenApiPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $kinds = [RouteKind::Api, RouteKind::Stream, RouteKind::GraphQl];
        if ($payload->withPages()) {
            $kinds[] = RouteKind::Page;
        }

        $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode(
                $this->exporter->build($kinds),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        if ($payload->asDownload()) {
            $resource->setHeader('Content-Disposition', 'attachment; filename="openapi.json"');
        }

        return $resource;
    }
}
