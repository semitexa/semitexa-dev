<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Intake;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Dev\Application\Service\Ai\State\Phase;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Application\Service\Ai\Trace\TraceStore;
use Semitexa\Dev\Application\Service\Ai\Work\Epic;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;

/**
 * Signals into the backlog as ideas (tk-intake-from-runtime).
 *
 * A signal is a candidate, not a confirmed defect, so it is filed unapproved:
 * it counts for nothing until the owner says it is real, and then it counts
 * under work, where a bug belongs. Its recipe is no bug recipe, which would
 * approve it by rule the moment it is filed.
 *
 * Seen again, an idea nobody has touched gets the new count; a task someone
 * approved, took or wrote a note on is theirs and is left as it is; a closed
 * one stays closed and is not filed again.
 */
#[AsService]
final class RuntimeIntake
{
    public const RECIPE = 'runtime_signal';

    public const EPIC = 'ep-runtime-intake';

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected TraceStore $traceStore;

    public function epicExists(string $epicId): bool
    {
        return $this->epicStore->exists($epicId);
    }

    /**
     * @param list<RuntimeSignal> $signals the loudest first
     * @param string $log the log they were read from, relative to the project
     * @return array{new: list<array<string, mixed>>, seen_again: list<array<string, mixed>>, kept: list<array<string, mixed>>, closed: list<array<string, mixed>>, unchanged: int, over_limit: int, epic_created: bool}
     */
    public function run(array $signals, bool $write, int $limit, string $epicId, string $log, string $now): array
    {
        $result = ['new' => [], 'seen_again' => [], 'kept' => [], 'closed' => [], 'unchanged' => 0, 'over_limit' => 0, 'epic_created' => false];
        foreach ($signals as $signal) {
            $id = $signal->taskId();
            $row = ['id' => $id, 'count' => $signal->count, 'last_seen' => $signal->lastSeen];
            if (!$this->taskStore->exists($id)) {
                if (count($result['new']) >= $limit) {
                    $result['over_limit']++;
                    continue;
                }
                $result['new'][] = $row + ['title' => $signal->title(), 'context_refs' => $signal->contextRefs()];
                if ($write) {
                    $result['epic_created'] = $this->ensureEpic($epicId, $now) || $result['epic_created'];
                    $this->file($signal, $epicId, $log, $now);
                }
                continue;
            }

            $task = $this->taskStore->get($id);
            if (in_array($task->status, [TaskStatus::DONE, TaskStatus::DISCARDED], true)) {
                $result['closed'][] = $row + ['status' => $task->status->value];
                continue;
            }
            if (!self::untouched($task)) {
                $result['kept'][] = $row + ['status' => $task->status->value, 'approved' => $task->isApproved()];
                continue;
            }
            $nextStep = $signal->nextStep($log);
            if ($task->nextStep === $nextStep) {
                $result['unchanged']++;
                continue;
            }
            $result['seen_again'][] = $row;
            if ($write) {
                // Checked again under the lock: an approval may land between the read and the write.
                $this->taskStore->change($id, static fn (Task $current): Task => self::untouched($current) ? $current->with(nextStep: $nextStep, updatedAt: $now) : $current);
            }
        }

        return $result;
    }

    /** An idea as the intake filed it: unapproved, not taken, its next step the intake's to the last word. */
    private static function untouched(Task $task): bool
    {
        return $task->approval === null
            && $task->status === TaskStatus::NEW
            && RuntimeSignal::wroteNextStep($task->id, $task->nextStep);
    }

    private function ensureEpic(string $epicId, string $now): bool
    {
        if ($this->epicStore->exists($epicId)) {
            return false;
        }
        $this->epicStore->save(new Epic(
            $epicId,
            'Errors the running project logged',
            'What the running project logs as errors reaches the backlog as ideas linked to the log: the owner approves the real defects, the rest is discarded as the environment.',
            EpicStatus::NEW,
            $now,
            $now,
        ));

        return true;
    }

    private function file(RuntimeSignal $signal, string $epicId, string $log, string $now): void
    {
        $id = $signal->taskId();
        $title = $signal->title();
        $this->traceStore->openOrCreate($id, topic: $title, recipe: self::RECIPE);
        $task = new Task(
            id:          $id,
            epicId:      $epicId,
            title:       $title,
            status:      TaskStatus::NEW,
            recipe:      self::RECIPE,
            risk:        'medium',
            contextRefs: $signal->contextRefs(),
            nextStep:    $signal->nextStep($log),
            traceId:     $id,
            createdAt:   $now,
            updatedAt:   $now,
            approval:    null,
            phase:       Phase::WORK,
        );
        $this->taskStore->save($task);
        $this->traceStore->append($id, TraceEventKind::TASK_RESULT, "task '{$id}' filed by ai:work:intake — {$title}", [
            'artifact'     => 'semitexa.ai-work.task-start/v1',
            'task_id'      => $id,
            'epic_id'      => $epicId,
            'recipe'       => self::RECIPE,
            'phase'        => Phase::WORK->value,
            'context_refs' => $task->contextRefs,
            'next_step'    => $task->nextStep,
            'fingerprint'  => $signal->fingerprint,
        ]);
    }
}
