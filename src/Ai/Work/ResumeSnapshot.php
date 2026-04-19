<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

use Semitexa\Dev\Ai\Trace\TraceEvent;
use Semitexa\Dev\Ai\Trace\TraceHeader;

/**
 * Result of {@see ResumeService::resume()}. Immutable, pure data — the
 * command layer formats it.
 */
final readonly class ResumeSnapshot
{
    /**
     * @param list<TraceEvent> $tailEvents
     */
    public function __construct(
        public Task $task,
        public ?TraceHeader $traceHeader,
        public array $tailEvents,
        public ?string $traceWarning,
    ) {}
}
