<?php

declare(strict_types=1);

namespace Semitexa\Dev\Ai\Work;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Support\ProjectRoot;

/**
 * File-backed store for epics under `<projectRoot>/var/ai-work/epics/`.
 *
 *   One epic = one `<epic_id>.json` file.
 *
 * `taskIds` on an Epic is derived by scanning the TaskStore on load — the
 * persisted epic file never holds a task list, so creating/deleting a task
 * is a single-file write and can never leave the epic file out of sync.
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
        return $epic->withTaskIds($this->taskStore->taskIdsForEpic($epicId));
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
            } catch (\RuntimeException) {
                continue;
            }
            $out[] = $epic->withTaskIds($this->taskStore->taskIdsForEpic($id));
        }
        usort($out, static fn(Epic $a, Epic $b) => strcmp($a->createdAt, $b->createdAt));
        return $out;
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
