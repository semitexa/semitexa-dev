<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ToolbarAssetPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Serves the toolbar's static files. The name is a map key, never a path
 * fragment, so nothing outside SERVED can reach the filesystem.
 */
#[AsPayloadHandler(payload: ToolbarAssetPayload::class, resource: ResourceResponse::class)]
final class ToolbarAssetHandler implements TypedHandlerInterface
{
    /** Everything this route will ever serve, and what it is. */
    private const SERVED = [
        'toolbar.js' => 'text/javascript; charset=utf-8',
        'toolbar.css' => 'text/css; charset=utf-8',
    ];

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ToolbarAssetPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $name = $payload->getName();
        $type = self::SERVED[$name] ?? null;

        // src/Application/Handler/PayloadHandler → package root is four levels up.
        $body = $type !== null && $this->gate->allows()
            ? @file_get_contents(dirname(__DIR__, 4) . '/resources/toolbar/' . $name)
            : false;

        if ($body === false) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', (string) $type)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setContent($body);
    }
}
