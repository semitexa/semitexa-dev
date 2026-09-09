<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Log\StaticLoggerBridge;
use Semitexa\Core\Support\ProjectRoot;

/**
 * File-backed store for epics under `<projectRoot>/var/ai-work/epics/`.
 *
 *   One epic = one `<epic_id>.json` file.
 *
 * `taskIds` on an Epic is derived by scanning the TaskStore on load — the
 * persisted epic file never holds a task list, so creating/deleting a task
 * is a single-file write and can never leave the epic file out of sync.
 * `lastActivityAt` rides along on the same scan, so an epic reports the last
 * time anything under it moved rather than the last time its own file did.
 *
 * Container-managed (#[AsService]). The TaskStore collaborator is injected
 * as a readonly property via #[InjectAsReadonly] — same channel every other
 * Semitexa service uses.
 */
#[AsService]
final class EpicStore
{
    public const SUBDIR = 'var/ai-work/epics';

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    public function exists(string $epicId): bool
    {
        WorkId::assertValid($epicId, 'epic id');
        return is_file($this->pathFor($epicId));
    }

    /**
     * Returns the epic with {@see Epic::$taskIds} populated by a TaskStore
     * scan. Throws if the epic file is missing.
     */
    public function get(string $epicId): Epic
    {
        WorkId::assertValid($epicId, 'epic id');
        $path = $this->pathFor($epicId);
        if (!is_file($path)) {
            throw new \RuntimeException("epic '{$epicId}' does not exist");
        }
        $epic = Epic::fromArray(JsonFile::read($path));
        $tasks = $this->taskStore->list($epicId);

        return $epic->withTaskIds(
            array_map(static fn(Task $t) => $t->id, $tasks),
            self::lastActivity($epic->updatedAt, $tasks),
        );
    }

    public function save(Epic $epic): void
    {
        WorkId::assertValid($epic->id, 'epic id');
        JsonFile::writeAtomic($this->pathFor($epic->id), $epic->toFileArray());
    }

    /**
     * @return list<Epic>
     */
    public function list(): array
    {
        $dir = $this->dir();
        if (!is_dir($dir)) {
            return [];
        }
        // One pass for every task, not one pass per epic: the per-epic helper
        // rescans the whole task directory, which turned a listing of 292 epics
        // into 292 scans of 1041 files.
        $tasksByEpic = $this->taskStore->allByEpic();

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
                $epic = Epic::fromArray(JsonFile::read($dir . '/' . $entry));
            } catch (\RuntimeException $e) {
                // Stay resilient — one corrupt file must not break the listing —
                // but never drop it silently: a partial write / hand-edit would
                // otherwise vanish from `ai:epic list` leaving a clean shorter
                // list with no signal.
                StaticLoggerBridge::warning('dev', 'Skipping unreadable epic record', [
                    'file' => $dir . '/' . $entry,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            $tasks = $tasksByEpic[$id] ?? [];
            $out[] = $epic->withTaskIds(
                array_map(static fn(Task $t) => $t->id, $tasks),
                self::lastActivity($epic->updatedAt, $tasks),
            );
        }
        usort($out, static fn(Epic $a, Epic $b) => strcmp($a->createdAt, $b->createdAt));
        return $out;
    }

    /**
     * The latest of the epic's own timestamp and its tasks'. Timestamps are
     * ISO-8601 UTC throughout ai-work, so a string compare is the right compare.
     *
     * @param list<Task> $tasks
     */
    private static function lastActivity(string $epicUpdatedAt, array $tasks): string
    {
        $last = $epicUpdatedAt;
        foreach ($tasks as $task) {
            if (strcmp($task->updatedAt, $last) > 0) {
                $last = $task->updatedAt;
            }
        }
        return $last;
    }

    public function pathFor(string $epicId): string
    {
        return $this->dir() . '/' . $epicId . '.json';
    }

    private function dir(): string
    {
        return ProjectRoot::get() . '/' . self::SUBDIR;
    }
}
