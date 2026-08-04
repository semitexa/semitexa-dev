<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Payload\Request;

use Semitexa\Core\Attribute\AsPublicPayload;
use Semitexa\Core\Http\Response\ResourceResponse;

/**
 * A class as the project graph knows it, at `/__trace/node`.
 *
 * Reached by clicking a step in a trace: the trace says a handler ran, this says
 * what that handler is wired to. Public and dev-gated for the same reason as
 * {@see TracePayload}.
 */
#[AsPublicPayload(
    path: '/__trace/node',
    methods: ['GET'],
    responseWith: ResourceResponse::class,
)]
final class TraceNodePayload
{
    /** Fully-qualified class name to look up. */
    public string $class = '';

    /** Trace to return to, so the back link goes where the reader came from. */
    public string $from = '';

    /**
     * The hydrator fills payloads through set{CamelCase}() rather than by writing
     * properties, so a public property alone is never populated.
     */
    public function setClass(mixed $value): void
    {
        $this->class = is_string($value) ? $value : '';
    }

    public function setFrom(mixed $value): void
    {
        $this->from = is_string($value) ? $value : '';
    }
}
