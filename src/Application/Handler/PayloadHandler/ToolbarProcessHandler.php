<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ToolbarProcessPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ObservatoryReader;

/**
 * Answers `{"begin": {...}|null, "end": {...}|null}` for one journal process.
 * A null `end` means the request has not finished journaling yet — the
 * toolbar asks again — as often as it means the id is unknown.
 */
#[AsPayloadHandler(payload: ToolbarProcessPayload::class, resource: ResourceResponse::class)]
final class ToolbarProcessHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected ObservatoryReader $reader;

    public function handle(ToolbarProcessPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $id = $payload->getId();
        if (!$this->gate->allows() || preg_match('/^p-[0-9]+-[a-f0-9]{4,32}$/', $id) !== 1) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($this->reader->find($id), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
