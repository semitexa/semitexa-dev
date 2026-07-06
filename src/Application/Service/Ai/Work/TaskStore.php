<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\ProjectRoot;

/**
 * File-backed store for tasks under `<projectRoot>/var/ai-work/tasks/`.
 *
 *   One task = one `<task_id>.json` file
 *   - Writes are atomic (temp + rename via {@see JsonFile}).
 *   - Ids are validated against {@see WorkId::PATTERN} (also the trace id
 *     pattern — tasks and their linked traces can share the same slug).
 *
 * The store carries no index; listing walks the directory. Given the
 * expected scale (dozens to a few hundred tasks per project) that's fine,
 * and avoids a parallel index file that can drift.
 *
 * Container-managed (#[AsService]) so commands and services can pull it
 * via #[InjectAsReadonly]. Project root is resolved lazily from
 * {@see ProjectRoot::get()}.
 */
#[AsService]
final class TaskStore
{
    public const SUBDIR = 'var/ai-work/tasks';

    public function exists(string $taskId): bool
    {
        WorkId::assertValid($taskId, 'task id');
        return is_file($this->pathFor($taskId));
    }

    public function get(string $taskId): Task
    {
        WorkId::assertValid($taskId, 'task id');
        $path = $this->pathFor($taskId);
        if (!is_file($path)) {
            throw new \RuntimeException("task '{$taskId}' does not exist");
        }
        return Task::fromArray(JsonFile::read($path));
    }

    public function save(Task $task): void
    {
        WorkId::assertValid($task->id, 'task id');
        WorkId::assertValid($task->epicId, 'epic id');
        WorkId::assertValid($task->traceId, 'trace id');
        JsonFile::writeAtomic($this->pathFor($task->id), $task->toArray());
    }

    /**
     * Enumerate tasks with optional filters. Results sorted by created_at
     * so output is stable regardless of filesystem order.
     *
     * @return list<Task>
     */
    public function list(?string $epicId = null, ?TaskStatus $status = null): array
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, '.json')) {
                continue;
            }
            $id = substr($entry, 0, -strlen('.json'));
            if (!preg_match(WorkId::PATTERN, $id)) {
                continue;
            }
            try {
                $task = Task::fromArray(JsonFile::read($dir . '/' . $entry));
            } catch (\RuntimeException $e) {
                // Stay resilient — one corrupt file must not break the listing —
                // but never drop it silently: a partial write / hand-edit would
                // otherwise vanish from `ai:work list` leaving a clean shorter
                // list with no signal.
                StaticLoggerBridge::warning('dev', 'Skipping unreadable task record', [
                    'file' => $dir . '/' . $entry,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            if ($epicId !== null && $task->epicId !== $epicId) {
                continue;
            }
            if ($status !== null && $task->status !== $status) {
                continue;
            }
            $out[] = $task;
        }
        usort($out, static fn(Task $a, Task $b) => strcmp($a->createdAt, $b->createdAt));
        return $out;
    }

    /**
     * @return list<string>
     */
    public function taskIdsForEpic(string $epicId): array
    {
        return array_map(static fn(Task $t) => $t->id, $this->list($epicId));
    }

    public function pathFor(string $taskId): string
    {
        return $this->dir() . '/' . $taskId . '.json';
    }

    private function dir(): string
    {
        return ProjectRoot::get() . '/' . self::SUBDIR;
    }
}
