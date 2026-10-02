<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The file one piece of evidence holds, for the Evidence view's preview. Dev
 * only. The id is matched against the store's id pattern before it names
 * anything on disk.
 */
#[AsPublicPayload(
    path: '/__observatory/evidence/file',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryEvidenceFilePayload
{
    public string $id = '';

    public function setId(mixed $value): void
    {
        $this->id = is_string($value) ? substr($value, 0, 64) : '';
    }
}
