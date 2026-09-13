<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * The log lines belonging to one block of the live picture.
 *
 * Gated like the panel, and then gated again: see ObservatoryLogAccess, which
 * refuses this content outside dev regardless of who opened the panel.
 */
#[AsPublicPayload(
    path: '/__observatory/logs',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class ObservatoryLogsPayload
{
    /** The span name the panel is asking about, e.g. `pipeline.handler`. */
    public string $block = '';

    /**
     * Hydration goes through setters here, as it does on the sibling payloads.
     *
     * The shape is narrowed at the door rather than trusted: a span name is
     * lower-case words joined by dots, and that is all this ever needs to be.
     * Anything else becomes the empty string, which the reader answers with an
     * empty list. The value reaches a filename-adjacent code path and a value
     * that never had to be a span name has no business travelling further in
     * to be checked by something with more to lose.
     */
    public function setBlock(mixed $value): void
    {
        $this->block = is_string($value) && preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $value) === 1
            ? $value
            : '';
    }
}
