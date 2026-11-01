<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Charter;

/**
 * One thing Semitexa asks the project's owner, through the agent between
 * them: the question, why it matters, and how the answer is recorded.
 */
final readonly class CharterQuestion
{
    public function __construct(
        public string $field,
        public string $question,
        public string $why,
        public string $record,
    ) {}

    /** @return array{field: string, question: string, why: string, record: string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'question' => $this->question, 'why' => $this->why, 'record' => $this->record];
    }
}
