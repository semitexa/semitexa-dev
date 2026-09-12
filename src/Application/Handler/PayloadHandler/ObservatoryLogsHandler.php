<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Dev\Application\Payload\Request\ObservatoryLogsPayload;
use Semitexa\Dev\Application\Service\Trace\ObservatoryLogAccess;
use Semitexa\Dev\Application\Service\Trace\ObservatoryLogReader;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * Log lines for one block, behind TWO gates.
 *
 * The panel gate answers "may you see this surface at all" and admits monitor
 * mode on production. The log gate answers "may you see THIS content", and
 * does not — see {@see ObservatoryLogAccess} for why the two differ.
 *
 * A refusal is a 403 carrying its reason, not the panel's usual 404. The 404
 * exists so a stranger cannot learn the panel is here; whoever reaches this
 * route has already passed that gate, so hiding the endpoint from them buys
 * nothing and costs them the sentence explaining where the logs actually are.
 */
#[AsPayloadHandler(payload: ObservatoryLogsPayload::class, resource: ResourceResponse::class)]
final class ObservatoryLogsHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    #[InjectAsReadonly]
    protected ObservatoryLogAccess $logAccess;

    #[InjectAsReadonly]
    protected ObservatoryLogReader $reader;

    public function handle(ObservatoryLogsPayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allows()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        if (!$this->logAccess->allows()) {
            return $this->json($resource, [
                'block' => $payload->block,
                'allowed' => false,
                'reason' => $this->logAccess->refusalReason(),
                'lines' => [],
            ], HttpStatus::Forbidden->value);
        }

        return $this->json($resource, [
            'block' => $payload->block,
            'allowed' => true,
            'lines' => $this->reader->forBlock($payload->block),
        ], HttpStatus::Ok->value);
    }

    /** @param array<string, mixed> $body */
    private function json(ResourceResponse $resource, array $body, int $status): ResourceResponse
    {
        return $resource
            ->setStatusCode($status)
            ->setHeader('Content-Type', 'application/json; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
