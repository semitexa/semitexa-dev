<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

/**
 * What proves a closed task, kept on the task: an ai:verify receipt is pruned
 * within days, a task is not.
 *
 * `tests` are the passed test targets of that run. None means the task had
 * nothing to test, which only a change of docs or config can say.
 */
final readonly class TaskProof
{
    /**
     * @param list<string> $tests
     * @param array<string, string> $commits repository => commit, when the commit holds what the run checked
     */
    public function __construct(
        public string $receipt,
        public string $at,
        public array $tests,
        public array $commits,
    ) {}

    /** @return array{receipt: string, at: string, tests: list<string>, commits: array<string, string>} */
    public function toArray(): array
    {
        return ['receipt' => $this->receipt, 'at' => $this->at, 'tests' => $this->tests, 'commits' => $this->commits];
    }

    /** Anything short of a receipt and its time is no proof. */
    public static function fromStored(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['receipt'] ?? null) || !is_string($row['at'] ?? null) || $row['receipt'] === '' || $row['at'] === '') {
            return null;
        }
        $commits = [];
        foreach (is_array($row['commits'] ?? null) ? $row['commits'] : [] as $repository => $commit) {
            if (is_string($commit)) {
                $commits[(string) $repository] = $commit;
            }
        }

        return new self(
            $row['receipt'],
            $row['at'],
            array_values(array_filter(is_array($row['tests'] ?? null) ? $row['tests'] : [], is_string(...))),
            $commits,
        );
    }
}
