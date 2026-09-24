<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Presence;

/**
 * One agent working in this workspace: who it is, what it said it is doing,
 * which task it holds, and when it last showed signs of life.
 */
final readonly class AgentSession
{
    /** Silent longer than this and a session is no longer counted as working. */
    public const LIVE_SECONDS = 900;

    /**
     * @param list<string> $repos what the agent said it will edit (packages/semitexa-x, src, ...)
     */
    public function __construct(
        public string $id,
        public string $agent,
        public string $intent,
        public ?string $task,
        public array $repos,
        public string $startedAt,
        public string $beatAt,
        public ?string $endedAt = null,
    ) {
    }

    public function isLive(int $now): bool
    {
        return $this->endedAt === null && $now - (int) strtotime($this->beatAt) <= self::LIVE_SECONDS;
    }

    public function secondsSilent(int $now): int
    {
        return max(0, $now - (int) strtotime($this->beatAt));
    }

    public function with(?string $task = null, ?string $beatAt = null, ?string $endedAt = null, bool $clearTask = false): self
    {
        return new self(
            $this->id,
            $this->agent,
            $this->intent,
            $clearTask ? null : ($task ?? $this->task),
            $this->repos,
            $this->startedAt,
            $beatAt ?? $this->beatAt,
            $endedAt ?? $this->endedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent,
            'intent' => $this->intent,
            'task' => $this->task,
            'repos' => $this->repos,
            'started_at' => $this->startedAt,
            'beat_at' => $this->beatAt,
            'ended_at' => $this->endedAt,
        ];
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['agent'] ?? ''),
            (string) ($row['intent'] ?? ''),
            is_string($row['task'] ?? null) ? $row['task'] : null,
            array_values(array_map('strval', (array) ($row['repos'] ?? []))),
            (string) ($row['started_at'] ?? ''),
            (string) ($row['beat_at'] ?? ''),
            is_string($row['ended_at'] ?? null) ? $row['ended_at'] : null,
        );
    }
}
