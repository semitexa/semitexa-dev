<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Task: the unit of executable work an agent picks up and resumes.
 *
 * Deliberately does NOT store a notes array — free-form history belongs on
 * the linked trace ({@see \Semitexa\Dev\Application\Service\Ai\Trace\TraceStore}). The task
 * carries only the *current* next step so a resuming agent can act without
 * replaying history it doesn't need.
 *
 * `traceId` is required: every task links to a trace, created on demand by
 * {@see TaskStore::start()}. `contextRefs` is a free-form list of paths or
 * symbols the task is anchored to — these stay small (the agent doesn't
 * need everything the repo contains, only what's load-bearing for this
 * task).
 */
final readonly class Task
{
    public const SCHEMA_VERSION = 'semitexa.ai-work.task/v1';

    /**
     * @param list<string> $contextRefs
     */
    public function __construct(
        public string $id,
        public string $epicId,
        public string $title,
        public TaskStatus $status,
        public string $recipe,
        public string $risk,
        public array $contextRefs,
        public ?string $nextStep,
        public string $traceId,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /**
     * @param list<string>|null $contextRefs
     */
    public function with(
        ?string $title = null,
        ?TaskStatus $status = null,
        ?string $recipe = null,
        ?string $risk = null,
        ?array $contextRefs = null,
        ?string $nextStep = null,
        ?string $updatedAt = null,
    ): self {
        return new self(
            id:           $this->id,
            epicId:       $this->epicId,
            title:        $title ?? $this->title,
            status:       $status ?? $this->status,
            recipe:       $recipe ?? $this->recipe,
            risk:         $risk ?? $this->risk,
            contextRefs:  $contextRefs ?? $this->contextRefs,
            nextStep:     $nextStep ?? $this->nextStep,
            traceId:      $this->traceId,
            createdAt:    $this->createdAt,
            updatedAt:    $updatedAt ?? $this->updatedAt,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind'           => 'task',
            'schema_version' => self::SCHEMA_VERSION,
            'id'             => $this->id,
            'epic_id'        => $this->epicId,
            'title'          => $this->title,
            'status'         => $this->status->value,
            'recipe'         => $this->recipe,
            'risk'           => $this->risk,
            'context_refs'   => $this->contextRefs,
            'next_step'      => $this->nextStep,
            'trace_id'       => $this->traceId,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $refs = $row['context_refs'] ?? [];
        if (!is_array($refs)) {
            $refs = [];
        }
        $normalisedRefs = [];
        foreach ($refs as $ref) {
            if (is_string($ref) && $ref !== '') {
                $normalisedRefs[] = $ref;
            }
        }

        return new self(
            id:           (string) ($row['id'] ?? ''),
            epicId:       (string) ($row['epic_id'] ?? ''),
            title:        (string) ($row['title'] ?? ''),
            status:       TaskStatus::parse((string) ($row['status'] ?? 'new')),
            recipe:       (string) ($row['recipe'] ?? ''),
            risk:         (string) ($row['risk'] ?? 'medium'),
            contextRefs:  $normalisedRefs,
            nextStep:     isset($row['next_step']) && $row['next_step'] !== null ? (string) $row['next_step'] : null,
            traceId:      (string) ($row['trace_id'] ?? ''),
            createdAt:    (string) ($row['created_at'] ?? ''),
            updatedAt:    (string) ($row['updated_at'] ?? ''),
        );
    }
}
