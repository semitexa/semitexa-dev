<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Lifecycle\CurrentRequestStore;
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

        // POST only. PayloadHydrator fills setters from the query string on
        // every method, so `GET /__observatory/stage?on=1` reached this branch
        // and switched recording on for the whole stack — a read that wrote.
        // Raised in review of semitexa-dev#78.
        $applied = null;
        if ($payload->on !== null && $this->isPost()) {
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

    /**
     * Whether this request may change state.
     *
     * Read from the live request rather than the payload: the payload is
     * hydrated identically for GET and POST, which is the whole reason this
     * check exists. No request in scope (a unit test, a CLI probe) counts as
     * not a POST — the safe answer for a switch.
     */
    private function isPost(): bool
    {
        $request = CurrentRequestStore::get();

        return $request !== null && strtoupper($request->getMethod()) === 'POST';
    }
}
