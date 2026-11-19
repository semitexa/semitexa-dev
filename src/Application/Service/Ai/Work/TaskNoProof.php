<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Why a closed task has no proof, kept on the task: the escape for work no
 * test can prove (a manual acceptance run, files outside the workspace, a text
 * that was later replaced).
 *
 * Such a task counts as done and is shown apart, "N closed with a reason"
 * (dec-closed-with-reason-counts-shown-apart), so every use stays visible.
 * A proof found at closing wins over a reason, and reopening drops both.
 */
final readonly class TaskNoProof
{
    /**
     * The shortest reason a close accepts (the same floor as the lint markers):
     * a reason is read later by someone deciding whether the close was fair.
     * Stored reasons are read whatever their length.
     */
    public const MIN_REASON = 20;

    public function __construct(
        public string $reason,
        public string $at,
        public ?string $by = null,
    ) {}

    /** Null for no reason at all: blank says nothing, and nothing is not a reason. */
    public static function because(?string $reason, string $at, ?string $by = null): ?self
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : new self($reason, $at, $by === null || trim($by) === '' ? null : trim($by));
    }

    /** @return array{reason: string, at: string, by: ?string} */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'at' => $this->at, 'by' => $this->by];
    }

    public static function fromStored(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['reason'] ?? null) || !is_string($row['at'] ?? null) || $row['at'] === '') {
            return null;
        }

        return self::because($row['reason'], $row['at'], is_string($row['by'] ?? null) ? $row['by'] : null);
    }
}
