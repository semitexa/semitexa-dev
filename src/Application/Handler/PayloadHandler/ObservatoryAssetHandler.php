<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryAssetPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryHtmlRenderer;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\ProjectGraph\Application\Service\Query\GraphViewerAssets;

/**
 * Serves the panel's static files: its own two, and the project graph
 * viewer's two, which semitexa/project-graph owns (its HTML export inlines
 * the same files) and only the dev-tools gate may fetch.
 *
 * The requested name is a MAP KEY, never a path fragment: a name that is not
 * one of the literals below never reaches the filesystem, so there is no
 * traversal to defend against rather than a defence to get right. Anything
 * else is a 404, like the panel itself.
 */
#[AsPayloadHandler(payload: ObservatoryAssetPayload::class, resource: ResourceResponse::class)]
final class ObservatoryAssetHandler implements TypedHandlerInterface
{
    /** Everything this route will ever serve, and what it is. */
    private const SERVED = [
        'observatory.css' => 'text/css; charset=utf-8',
        'observatory.js' => 'text/javascript; charset=utf-8',
    ];

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ObservatoryAssetPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $name = $payload->getName();

        if (isset(GraphViewerAssets::FILES[$name])) {
            $type = GraphViewerAssets::FILES[$name];
            $body = $this->gate->allowsDevTools() ? GraphViewerAssets::read($name) : null;
        } else {
            $type = self::SERVED[$name] ?? null;
            // Safe to build only once $name is one of the keys above.
            $body = $type !== null && $this->gate->allows()
                ? @file_get_contents(ObservatoryHtmlRenderer::assetDir() . '/' . $name)
                : null;
        }

        if ($type === null || $body === null || $body === false) {
            return $this->notFound($resource);
        }

        return $resource
            ->setHeader('Content-Type', $type)
            // Same as the page. A dev panel that serves a cached script after a
            // package update shows yesterday's bug, and two extra requests on a
            // local tool are not worth a cache-busting scheme to avoid.
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
