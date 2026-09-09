<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Epic: the top-level container a cluster of related tasks rolls up to.
 *
 * `taskIds` is derived at load time by scanning the task directory — it is
 * not persisted on the epic file, so there's no dual-write consistency
 * problem when a task is created or deleted. See {@see EpicStore::get()}.
 *
 * `lastActivityAt` is derived the same way and for the same reason. `updatedAt`
 * only ever records a write to the epic file itself, so an epic whose tasks are
 * worked on daily keeps whatever date its goal was last edited — which reads,
 * to anyone scanning a listing, as abandoned work.
 */
final readonly class Epic
{
    public const SCHEMA_VERSION = 'semitexa.ai-work.epic/v1';

    /**
     * @param list<string> $taskIds
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $goal,
        public EpicStatus $status,
        public string $createdAt,
        public string $updatedAt,
        public array $taskIds = [],
        public string $lastActivityAt = '',
    ) {}

    /**
     * The most recent activity anywhere under this epic — its own `updatedAt`,
     * or a task's if a task moved later. Falls back to `updatedAt` for an Epic
     * built without the store (tests, `fromArray`), so a caller never has to
     * ask which of the two fields it is holding.
     *
     * The max() is not belt-and-braces. `with()` carries the task-derived value
     * across while advancing `updatedAt`, which is exactly what `ai:epic update`
     * does, so a plain read of the stored field could report activity older than
     * the write that just happened. Taking the later of the two makes the
     * invariant — last activity is never before the epic's own last write —
     * hold however the object was assembled, rather than in the paths someone
     * remembered to patch.
     */
    public function lastActivity(): string
    {
        if ($this->lastActivityAt === '') {
            return $this->updatedAt;
        }

        return strcmp($this->lastActivityAt, $this->updatedAt) >= 0
            ? $this->lastActivityAt
            : $this->updatedAt;
    }

    public function with(
        ?string $title = null,
        ?string $goal = null,
        ?EpicStatus $status = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            id:        $this->id,
            title:     $title ?? $this->title,
            goal:      $goal ?? $this->goal,
            status:    $status ?? $this->status,
            createdAt: $this->createdAt,
            updatedAt: $updatedAt ?? $this->updatedAt,
            taskIds:   $this->taskIds,
            lastActivityAt: $this->lastActivityAt,
        );
    }

    /**
     * @param list<string> $taskIds
     */
    public function withTaskIds(array $taskIds, string $lastActivityAt = ''): self
    {
        return new self(
            id:        $this->id,
            title:     $this->title,
            goal:      $this->goal,
            status:    $this->status,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            taskIds:   $taskIds,
            lastActivityAt: $lastActivityAt,
        );
    }

    /**
     * On-disk shape (task_ids is derived — kept out of the persisted file).
     *
     * @return array<string, mixed>
     */
    public function toFileArray(): array
    {
        return [
            'kind'           => 'epic',
            'schema_version' => self::SCHEMA_VERSION,
            'id'             => $this->id,
            'title'          => $this->title,
            'goal'           => $this->goal,
            'status'         => $this->status->value,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
        ];
    }

    /**
     * Full API shape including derived task_ids.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toFileArray() + [
            'task_ids'         => $this->taskIds,
            'last_activity_at' => $this->lastActivity(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            id:        (string) ($row['id'] ?? ''),
            title:     (string) ($row['title'] ?? ''),
            goal:      (string) ($row['goal'] ?? ''),
            status:    EpicStatus::parse((string) ($row['status'] ?? 'new')),
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            taskIds:   [],
        );
    }
}
