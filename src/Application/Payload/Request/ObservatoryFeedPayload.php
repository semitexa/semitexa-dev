<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The JSON feed behind the live panel — the same snapshot `ai:observe ps`
 * consumes, served over HTTP for the polling page at `/__observatory`.
 */
#[AsPublicPayload(
    path: '/__observatory/feed',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryFeedPayload
{
    /**
     * `?stream=1` switches the feed from the folded snapshot to the journal
     * stream: rows appended since `after` (a cursor from the previous
     * response), or a bootstrap batch when `after` is empty. The snapshot
     * shape without `stream` is unchanged — `ai:observe ps` and older tabs
     * keep reading it.
     */
    public bool $stream = false;

    public string $after = '';

    public function setStream(mixed $value): void
    {
        $this->stream = $value === true || $value === '1' || $value === 1 || $value === 'true';
    }

    public function setAfter(mixed $value): void
    {
        $this->after = is_string($value) ? $value : '';
    }
}
