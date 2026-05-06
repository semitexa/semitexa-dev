<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Trace;

/**
 * Full read-back of a single trace: header + ordered events. Returned by
 * {@see TraceStore::read()} — never written to disk as a single blob (the
 * on-disk form is append-only NDJSON).
 */
final readonly class Trace
{
    /**
     * @param list<TraceEvent> $events in append order (event_id ascending)
     */
    public function __construct(
        public TraceHeader $header,
        public array $events,
    ) {}
}
