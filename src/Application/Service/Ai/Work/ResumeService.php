<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Dev\Application\Service\Ai\Trace\Trace;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEvent;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;

/**
 * Surfaces everything a resuming agent needs for a task, without mutating
 * task state: current next_step, context_refs, linked trace header, and
 * the tail of the trace.
 *
 * Why only the tail? The whole point of the trace is that it's append-only
 * history that doesn't need to be replayed to make progress. A resuming
 * agent should see:
 *   - what the task *is* (title, recipe, risk)
 *   - what came most recently (last N events)
 *   - what the agent told itself to do next (next_step)
 * That's enough to act. If the full history is wanted, `ai:trace show`
 * prints it.
 */
#[AsService]
final class ResumeService
{
    private const DEFAULT_TAIL = 5;

    #[InjectAsReadonly]
    protected TaskStore $tasks;

    #[InjectAsReadonly]
    protected TraceStore $traces;

    public function resume(string $taskId, int $tail = self::DEFAULT_TAIL): ResumeSnapshot
    {
        $task = $this->tasks->get($taskId);

        $traceHeader = null;
        /** @var list<TraceEvent> $tailEvents */
        $tailEvents = [];
        $traceWarning = null;

        if ($task->traceId !== '' && $this->traces->exists($task->traceId)) {
            try {
                $trace = $this->traces->read($task->traceId);
                $traceHeader = $trace->header;
                $tailEvents = $this->tailOf($trace, $tail);
            } catch (\RuntimeException $e) {
                $traceWarning = "failed to read trace '{$task->traceId}': " . $e->getMessage();
            }
        } elseif ($task->traceId !== '') {
            $traceWarning = "trace '{$task->traceId}' is linked but not found on disk";
        }

        return new ResumeSnapshot(
            task:         $task,
            traceHeader:  $traceHeader,
            tailEvents:   $tailEvents,
            traceWarning: $traceWarning,
        );
    }

    /**
     * @return list<TraceEvent>
     */
    private function tailOf(Trace $trace, int $tail): array
    {
        if ($tail <= 0) {
            return [];
        }
        if (count($trace->events) <= $tail) {
            return $trace->events;
        }
        return array_values(array_slice($trace->events, -$tail));
    }
}
