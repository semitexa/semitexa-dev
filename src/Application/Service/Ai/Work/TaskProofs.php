<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\CommitCoverage;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;

/**
 * Decides at the moment a task closes whether it is proven, because later work
 * on the same files makes a receipt stop holding without making it wrong.
 *
 * The task's newest ai:verify run is the one that speaks: a red run after a
 * green one means it is not proven. A passed test is the proof. With no test,
 * a pass proves a change of docs or config and nothing else: code nobody tested
 * is not proven by a lint. A task with no proof stays done and is shown as
 * unproven; closing it again after a run looks again.
 */
final readonly class TaskProofs
{
    private const TEST_TARGETS = ['phpunit', 'e2e'];
    private const NOTHING_TO_TEST = '/\.(md|txt|json|ya?ml|neon|xml|ini|toml|env|lock)$|(^|\/)\.env[^\/]*$/';

    public function __construct(private string $projectRoot) {}

    /** The task as its status now leaves it: closing looks for proof, reopening drops it. */
    public function settle(Task $task, ?TaskStatus $movedTo): Task
    {
        return match (true) {
            $movedTo === null => $task,
            $movedTo === TaskStatus::DONE => $task->withProof($this->find($task)),
            default => $task->withProof(null),
        };
    }

    public function find(Task $task): ?TaskProof
    {
        $receipt = (new VerifyReceipts($this->projectRoot))->newestFor([$task->id, $task->traceId]);
        if ($receipt === null || ($receipt['verdict'] ?? null) !== 'pass' || ($receipt['changed_during_run'] ?? []) !== []) {
            return null;
        }
        $id = $receipt['id'] ?? null;
        $at = $receipt['generated_at'] ?? null;
        if (!is_string($id) || !is_string($at)) {
            return null;
        }
        $tree = is_array($receipt['tree'] ?? null) ? $receipt['tree'] : [];
        $tests = self::passedTests($receipt);
        if ($tests === [] && !self::hasNothingToTest($tree)) {
            return null;
        }

        return new TaskProof($id, $at, $tests, $this->commits($tree));
    }

    /**
     * @param array<string, mixed> $receipt
     * @return list<string>
     */
    private static function passedTests(array $receipt): array
    {
        $tests = [];
        foreach (is_array($receipt['targets'] ?? null) ? $receipt['targets'] : [] as $target) {
            if (is_array($target) && is_string($target['id'] ?? null) && in_array($target['type'] ?? null, self::TEST_TARGETS, true) && ($target['status'] ?? null) === 'pass') {
                $tests[] = $target['id'];
            }
        }

        return $tests;
    }

    /** @param array<mixed> $tree */
    private static function hasNothingToTest(array $tree): bool
    {
        foreach (array_keys($tree) as $path) {
            if (preg_match(self::NOTHING_TO_TEST, (string) $path) !== 1) {
                return false;
            }
        }

        return $tree !== [];
    }

    /**
     * The commits holding what the run checked, and nothing else: closed before
     * committing, HEAD is someone else's change and the task must not name it.
     *
     * @param array<mixed> $tree
     * @return array<string, string>
     */
    private function commits(array $tree): array
    {
        /** @var array<string, ?string> $tree */
        $coverage = (new CommitCoverage($this->projectRoot))->of($tree, 'HEAD');

        return $coverage['differs'] === [] && $coverage['unchecked'] === [] && $coverage['unresolved'] === [] ? $coverage['commits'] : [];
    }
}
