<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Payload\Request\ObservatoryEvidencePayload;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceListQuery;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceRecord;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * The Evidence view's list: one page of `var/evidence/`, searched, filtered
 * by kind, data, visibility and the day it was recorded, sorted and paged on
 * the server — the store is not sent whole to be sorted in the browser.
 *
 * Behind the dev-tools gate: the store holds screenshots and traces of real
 * data, so the production monitor mode gets the panel's usual 404. Reads
 * only: the inbox is counted, not adopted — adopting moves files, and a GET
 * that moved files would do it on a crafted link.
 */
#[AsPayloadHandler(payload: ObservatoryEvidencePayload::class, resource: ResourceResponse::class)]
final class ObservatoryEvidenceHandler implements TypedHandlerInterface
{
    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ObservatoryEvidencePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        if (!$this->gate->allowsDevTools()) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        try {
            $query = EvidenceListQuery::fromInput($payload->input());
        } catch (\InvalidArgumentException $e) {
            return $this->json($resource, ['error' => 'bad-query', 'message' => $e->getMessage()], HttpStatus::BadRequest->value);
        }

        $store = new EvidenceStore(ProjectRoot::get());
        $all = $store->all();
        $page = $query->apply($all['records']);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return $this->json($resource, [
            'items' => array_map(static fn (EvidenceRecord $r): array => $r->toArray() + [
                'expired' => $r->isExpired($now),
                'path' => EvidenceStore::DIR . '/' . $r->id . '/' . $r->file,
            ], $page['items']),
            'total' => $page['total'],
            'page' => $page['page'],
            'pages' => $page['pages'],
            'per' => $query->per,
            'sort' => $query->sort,
            'dir' => $query->dir,
            'kinds' => $page['kinds'],
            'stored' => count($all['records']),
            'unreadable' => $all['unreadable'],
            'inbox_pending' => $store->inboxPending(),
            'unregistered' => $store->unregistered(),
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
