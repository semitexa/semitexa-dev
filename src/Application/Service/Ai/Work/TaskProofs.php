<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Dev\Application\Service\Ai\Verify\DirtyWorkspaceScanner;
use Semitexa\Dev\Application\Service\Ai\Verify\ProjectGuardTargets;
use Semitexa\Dev\Application\Service\Ai\Verify\Receipt\VerifyReceipts;
use Semitexa\Dev\Application\Service\Ai\Verify\UnselectedChanges;

/**
 * Decides at the moment a task closes whether it is proven, because later work
 * on the same files makes a receipt stop holding without making it wrong.
 *
 * The task's runs are read newest first. A run that is not a pass ends it: not
 * proven. The first run that checked code decides, and it needs a test of that
 * code: the whole-tree ratchets run on every PHP change and test none of it in
 * particular, and a run over a docs page says nothing about the code before it.
 * Only a task with no code in any run can count on a pass alone. The files must
 * still be what the run checked.
 *
 * A task with no proof stays done and is shown as unproven; closing it again
 * after a run looks again. Its commits are the ones that say so, with a line
 * "Task: <id>": anything else would be a guess from HEAD.
 */
final readonly class TaskProofs
{
    private const TEST_TARGETS = ['phpunit', 'e2e'];
    private const NOTHING_TO_TEST = '/\.(md|txt|json|ya?ml|neon|xml|ini|toml)$|(^|\/)\.env(\.[a-z]+)?$/';

    public function __construct(private string $projectRoot) {}

    /** The task as its status now leaves it: closing looks for proof, reopening drops it. */
    public function settle(Task $task, ?TaskStatus $movedTo): Task
    {
        return match (true) {
            $movedTo === null => $task,
            // Receipts are pruned within days: no run left says nothing against a proof kept on the task.
            $movedTo === TaskStatus::DONE => $task->withProof($this->runs($task) === [] ? $task->proof : $this->find($task)),
            default => $task->withProof(null),
        };
    }

    public function find(Task $task): ?TaskProof
    {
        $run = self::decidingRun($this->runs($task));
        if ($run === null || ($run['changed_during_run'] ?? []) !== []) {
            return null;
        }
        $id = $run['id'] ?? null;
        $at = $run['generated_at'] ?? null;
        if (!is_string($id) || !is_string($at)) {
            return null;
        }
        $tree = self::tree($run);
        $tests = self::passedTests($run);
        if ($tests === [] && !self::hasNothingToTest($tree)) {
            return null;
        }
        if ((new VerifyReceipts($this->projectRoot))->fingerprintOf(array_keys($tree)) != $tree) {
            return null;
        }

        return new TaskProof($id, $at, $tests, $this->commits($task, $tree));
    }

    /** @return list<array<string, mixed>> */
    private function runs(Task $task): array
    {
        return (new VerifyReceipts($this->projectRoot))->forTraces([$task->id, $task->traceId]);
    }

    /**
     * @param list<array<string, mixed>> $runs newest first
     * @return array<string, mixed>|null
     */
    private static function decidingRun(array $runs): ?array
    {
        $withoutCode = null;
        foreach ($runs as $run) {
            if (($run['verdict'] ?? null) !== 'pass') {
                return null;
            }
            if (!self::hasNothingToTest(self::tree($run))) {
                return $run;
            }
            $withoutCode ??= $run;
        }

        return $withoutCode;
    }

    /**
     * @param array<string, mixed> $run
     * @return array<string, ?string>
     */
    private static function tree(array $run): array
    {
        $tree = [];
        foreach (is_array($run['tree'] ?? null) ? $run['tree'] : [] as $path => $hash) {
            $tree[(string) $path] = is_string($hash) ? $hash : null;
        }

        return $tree;
    }

    /**
     * @param array<string, mixed> $run
     * @return list<string>
     */
    private static function passedTests(array $run): array
    {
        $tests = [];
        foreach (is_array($run['targets'] ?? null) ? $run['targets'] : [] as $target) {
            if (is_array($target) && is_string($target['id'] ?? null) && $target['id'] !== 'phpunit:' . ProjectGuardTargets::RATCHET_SUITE
                && in_array($target['type'] ?? null, self::TEST_TARGETS, true) && ($target['status'] ?? null) === 'pass') {
                $tests[] = $target['id'];
            }
        }

        return $tests;
    }

    /** @param array<string, ?string> $tree */
    private static function hasNothingToTest(array $tree): bool
    {
        foreach (array_keys($tree) as $path) {
            if (preg_match(self::NOTHING_TO_TEST, $path) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, ?string> $tree
     * @return array<string, list<string>> repository => the commits that name this task
     */
    private function commits(Task $task, array $tree): array
    {
        $repositories = (new DirtyWorkspaceScanner($this->projectRoot))->repositories();
        $commits = [];
        foreach (array_keys($tree) as $path) {
            $prefix = UnselectedChanges::repositoryOf($path, array_keys($repositories));
            if ($prefix === null || isset($commits[$prefix === '' ? '(project root)' : $prefix])) {
                continue;
            }
            $named = self::commitsNaming($repositories[$prefix], $task->id);
            if ($named !== []) {
                $commits[$prefix === '' ? '(project root)' : $prefix] = $named;
            }
        }

        return $commits;
    }

    /** @return list<string> */
    private static function commitsNaming(string $repository, string $taskId): array
    {
        // Read-only, scoped to this one repository (see DirtyWorkspaceScanner::statusOf).
        $process = proc_open(
            ['git', '-C', $repository, '-c', 'safe.directory=' . $repository, 'log', '--format=%H', '-E', '--grep=^Task: ' . preg_quote($taskId, '/') . '$'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return [];
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return proc_close($process) === 0 ? array_values(array_filter(explode("\n", $out))) : [];
    }
}
