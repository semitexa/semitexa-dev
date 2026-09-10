<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryStagePayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryMode;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ObservatoryStage;

/**
 * Reads or flips stage mode for the live panel. Setting it needs the full
 * instrument (dev), not just an open panel: monitor mode on a production box
 * may look, but must never start recording request internals.
 */
#[AsPayloadHandler(payload: ObservatoryStagePayload::class, resource: ResourceResponse::class)]
final class ObservatoryStageHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ObservatoryStagePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $applied = null;
        if ($payload->on !== null) {
            $applied = ObservatoryMode::full() ? ObservatoryStage::set($payload->on) : false;
        }

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode([
                'stage' => ObservatoryStage::isOn(),
                'available' => ObservatoryMode::full(),
                'applied' => $applied,
            ], JSON_UNESCAPED_SLASHES));
    }
}
