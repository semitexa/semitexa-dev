<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Handler\PayloadHandler;

use Semitexa\Core\Attribute\AsPayloadHandler;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Contract\TypedHandlerInterface;
use Semitexa\Core\Http\HttpStatus;
use Semitexa\Core\Http\Response\ResourceResponse;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Payload\Request\ObservatoryEvidenceFilePayload;
use Semitexa\Dev\Application\Service\Ai\Evidence\EvidenceStore;
use Semitexa\Dev\Application\Service\Trace\ObservatoryPanelGate;

/**
 * One evidence file, for the preview. Dev only, like the list.
 *
 * Whatever the file is, it is served under `Content-Security-Policy: sandbox`
 * — an opaque origin with no access to the panel, its cookies or its
 * endpoints. A graph export is a page that runs its own viewer, so HTML alone
 * gets `allow-scripts` inside that sandbox; an SVG or a text file that
 * happens to hold markup gets nothing. Never sniffed, never cached.
 */
#[AsPayloadHandler(payload: ObservatoryEvidenceFilePayload::class, resource: ResourceResponse::class)]
final class ObservatoryEvidenceFileHandler implements TypedHandlerInterface
{
    /** Extension => what it is served as. Anything else downloads. */
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'webm' => 'video/webm',
        'mp4' => 'video/mp4',
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'svg' => 'text/plain; charset=utf-8',
        'json' => 'text/plain; charset=utf-8',
        'ndjson' => 'text/plain; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'log' => 'text/plain; charset=utf-8',
        'md' => 'text/plain; charset=utf-8',
        'csv' => 'text/plain; charset=utf-8',
        'xml' => 'text/plain; charset=utf-8',
        'yaml' => 'text/plain; charset=utf-8',
        'yml' => 'text/plain; charset=utf-8',
        'dot' => 'text/plain; charset=utf-8',
        'ts' => 'text/plain; charset=utf-8',
        'js' => 'text/plain; charset=utf-8',
        'php' => 'text/plain; charset=utf-8',
    ];

    #[InjectAsReadonly]
    protected ObservatoryPanelGate $gate;

    public function handle(ObservatoryEvidenceFilePayload $payload, ResourceResponse $resource): ResourceResponse
    {
        $store = $this->gate->allowsDevTools() ? new EvidenceStore(ProjectRoot::get()) : null;
        $record = $store?->find($payload->id);
        $path = $record !== null ? $store->fileOf($record) : null;
        // The store only ever writes a regular file; a link in a hand-made
        // folder would serve whatever it points at (.env, /etc/passwd).
        $body = $path !== null && !is_link($path) ? @file_get_contents($path) : false;
        if ($store === null || $record === null || $body === false) {
            return $resource
                ->setStatusCode(HttpStatus::NotFound->value)
                ->setHeader('Content-Type', 'text/plain; charset=utf-8')
                ->setContent('Not Found');
        }

        $extension = strtolower(pathinfo($record->file, PATHINFO_EXTENSION));
        $type = self::TYPES[$extension] ?? null;

        return $resource
            ->setHeader('Content-Type', $type ?? 'application/octet-stream')
            ->setHeader('Content-Disposition', ($type === null ? 'attachment' : 'inline') . '; filename="' . addcslashes($record->file, "\"\\\r\n") . '"')
            ->setHeader('Content-Security-Policy', str_starts_with((string) $type, 'text/html') ? 'sandbox allow-scripts' : 'sandbox')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setContent($body);
    }
}
