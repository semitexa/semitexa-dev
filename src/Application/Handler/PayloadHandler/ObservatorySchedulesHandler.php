<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatorySchedulesPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;
use Semitexa\Dev\Application\Service\Trace\ScheduleCatalog;

#[AsPayloadHandler(payload: ObservatorySchedulesPayload::class, resource: ResourceResponse::class)]
final class ObservatorySchedulesHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected ScheduleCatalog $catalog;

    public function handle(ObservatorySchedulesPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        return $resource
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode([
                'generatedAt' => date('c'),
                'schedules' => $this->catalog->all(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
