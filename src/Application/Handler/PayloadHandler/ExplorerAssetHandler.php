<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ExplorerAssetPayload;
use Semitexa\Dev\Application\Service\Explorer\ExplorerHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Serves the Explorer's static files. The name is a map key, never a path
 * fragment, so nothing outside SERVED can reach the filesystem.
 */
#[AsPayloadHandler(payload: ExplorerAssetPayload::class, resource: ResourceResponse::class)]
final class ExplorerAssetHandler implements TypedHandlerInterface
{
    /** Everything this route will ever serve, and what it is. */
    private const SERVED = [
        'explorer.css' => 'text/css; charset=utf-8',
        'explorer.js' => 'text/javascript; charset=utf-8',
        'invoke.js' => 'text/javascript; charset=utf-8',
    ];

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ExplorerAssetPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $name = $payload->getName();
        $type = self::SERVED[$name] ?? null;

        if ($type === null || !$this->gate->allows()) {
            return $this->notFound($resource);
        }

        $body = @file_get_contents(ExplorerHtmlRenderer::assetDir() . '/' . $name);
        if ($body === false) {
            return $this->notFound($resource);
        }

        return $resource
            ->setHeader('Content-Type', $type)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContent($body);
    }

    private function notFound(ResourceResponse $resource): ResourceResponse
    {
        return $resource
            ->setStatusCode(HttpStatus::NotFound->value)
            ->setHeader('Content-Type', 'text/plain; charset=utf-8')
            ->setContent('Not Found');
    }
}
