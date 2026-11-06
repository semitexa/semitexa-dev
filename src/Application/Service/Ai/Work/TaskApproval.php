<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * Who approved a task for implementation, in whose words, and when.
 *
 * A task without one is an idea: it stays in the backlog and counts for
 * nothing in a project's progress. The words are kept because the command is
 * run by an agent on the owner's behalf, so what the owner said is the record.
 */
final readonly class TaskApproval
{
    public function __construct(
        public string $by,
        public string $words,
        public string $at,
    ) {
        if ($by === '' || $words === '' || $at === '') {
            throw new \InvalidArgumentException('an approval needs who gave it, their words and when');
        }
    }

    /** @return array{by: string, words: string, at: string} */
    public function toArray(): array
    {
        return ['by' => $this->by, 'words' => $this->words, 'at' => $this->at];
    }

    /**
     * Anything short of a whole record reads as no approval: a task counts as
     * approved only when it says who approved it and how.
     */
    public static function fromStored(mixed $row): ?self
    {
        if (!is_array($row)) {
            return null;
        }
        $by = $row['by'] ?? null;
        $words = $row['words'] ?? null;
        $at = $row['at'] ?? null;
        if (!is_string($by) || !is_string($words) || !is_string($at) || $by === '' || $words === '' || $at === '') {
            return null;
        }

        return new self($by, $words, $at);
    }
}
