<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Epic: the top-level container a cluster of related tasks rolls up to.
 *
 * `taskIds` is derived at load time by scanning the task directory — it is
 * not persisted on the epic file, so there's no dual-write consistency
 * problem when a task is created or deleted. See {@see EpicStore::get()}.
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
    ) {}

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
        );
    }

    /**
     * @param list<string> $taskIds
     */
    public function withTaskIds(array $taskIds): self
    {
        return new self(
            id:        $this->id,
            title:     $this->title,
            goal:      $this->goal,
            status:    $this->status,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
            taskIds:   $taskIds,
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
        return $this->toFileArray() + ['task_ids' => $this->taskIds];
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
