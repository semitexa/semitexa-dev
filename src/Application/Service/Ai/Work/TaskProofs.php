<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Work;

use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
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
 * A task that counts is not closed with no proof unless a reason is given
 * ({@see TaskNoProof}); one closed before that rule stays done and is shown as
 * unproven. Closing again after a run looks again. Its commits are the ones that say so, with a line
 * "Task: <id>": anything else would be a guess from HEAD.
 */
final readonly class TaskProofs
{
    /** What a refusal for want of proof says, so its next command can be told from a claim's. */
    public const NO_PROOF = 'has no proof';
    private const TEST_TARGETS = ['phpunit', 'e2e'];
    private const NOTHING_TO_TEST = '/\.(md|txt|json|ya?ml|neon|xml|ini|toml)$|(^|\/)\.env(\.[a-z]+)?$/';

    /**
     * @param (\Closure(Task): list<array<string, mixed>>)|null $readRuns how the
     *     receipts are read, newest first; a seam for tests, the receipts directory otherwise
     */
    public function __construct(private string $projectRoot, private ?\Closure $readRuns = null) {}

    /**
     * The task as its status now leaves it: closing looks for proof, reopening
     * drops it. With no proof the reason stands in, the one given now or the one
     * the task already carries; a proof found wins over both.
     */
    public function settle(Task $task, ?TaskStatus $movedTo, ?TaskNoProof $because = null): Task
    {
        return $this->settleOn($task, $movedTo, $because, $movedTo === TaskStatus::DONE ? $this->runs($task) : []);
    }

    /**
     * One read of the receipts decides both the proof and whether a failing run
     * stands. Two reads let a run that failed between them close the task with a
     * reason (review, dev#284).
     *
     * @param list<array<string, mixed>> $runs newest first
     */
    private function settleOn(Task $task, ?TaskStatus $movedTo, ?TaskNoProof $because, array $runs): Task
    {
        if ($movedTo === null) {
            return $task;
        }
        if ($movedTo !== TaskStatus::DONE) {
            return $task->withProof(null);
        }
        // Receipts are pruned within days: no run left says nothing against a proof kept on the task.
        $proof = $runs === [] ? $task->proof : $this->proofIn($task, $runs);
        // The write keeps the rule refusalToClose() checks first: a reason never
        // stands over a failing run, even one that finished between the check and
        // this read (review, dev#284). Such a close is left unproven, and counts nothing.
        $reason = $proof === null && self::failingRun($runs) === null ? ($because ?? $task->noProof) : null;

        return $task->withProof($proof, $reason);
    }

    /** The reason as it is given now, by this agent session; null when none is. */
    public static function because(?string $reason): ?TaskNoProof
    {
        $by = getenv(AgentRegistry::ENV);

        return TaskNoProof::because($reason, date('c'), is_string($by) ? $by : null);
    }

    /**
     * Why a reason given with this update is refused, or null. It is read only
     * when a task closes: anywhere else it was dropped with exit 0, and the agent
     * believed it was kept. A reason too short to judge is refused too (review of
     * part B). A blank one is left to refusalToClose(), which reads it as none.
     */
    public static function refusalOfReason(?string $given, ?TaskStatus $movedTo): ?string
    {
        if ($given === null) {
            return null;
        }
        if ($movedTo !== TaskStatus::DONE) {
            return '--no-proof-because closes a task: use it with --status=done';
        }
        $given = trim($given);

        return $given !== '' && mb_strlen($given) < TaskNoProof::MIN_REASON
            ? '--no-proof-because needs at least ' . TaskNoProof::MIN_REASON . ' characters: say what no test can reach'
            : null;
    }

    /**
     * Why this close is refused, or null. A task that counts (approved, or a
     * bug) closes on a proof or on a stated reason; history nobody approved
     * closes freely. Asked before the claim is touched: a refused close must
     * leave everything as it was.
     */
    public function refusalToClose(Task $task, ?TaskStatus $movedTo, ?TaskNoProof $because, ?string $recipe = null): ?string
    {
        if ($movedTo !== TaskStatus::DONE || !($task->isApproved() || TaskFiling::isBug($recipe ?? $task->recipe))) {
            return null;
        }
        $files = $task->contextRefs === [] ? '<its files>' : implode(',', $task->contextRefs);
        // A reason stands in for a test nobody can write, never for a run that
        // failed: given now or kept from before, it does not close past one
        // (review of part B: `--no-proof-because=x` closed a task whose latest
        // run had failed, the one case the rule forbids).
        $runs = $this->runs($task);
        $failing = self::failingRun($runs);
        if ($failing !== null) {
            $run = is_string($failing['id'] ?? null) ? " ({$failing['id']})" : '';

            return "task '{$task->id}' " . self::NO_PROOF . ": its latest ai:verify run{$run} failed, and neither a proof nor a reason closes past a failing run. Fix what it found, run `bin/semitexa ai:verify --trace={$task->traceId} --files={$files}` until it passes, and close again.";
        }
        if ($because !== null) {
            return null;
        }
        $settled = $this->settleOn($task, $movedTo, null, $runs);
        if ($settled->proof !== null || $settled->noProof !== null) {
            return null;
        }

        return "task '{$task->id}' " . self::NO_PROOF . ": no passing ai:verify run for it holds a test of its code. Run `bin/semitexa ai:verify --trace={$task->traceId} --files={$files}` and close again, or, when no test can prove it, close with `--no-proof-because=\"<why>\"`; the reason is kept on the task.";
    }

    public function find(Task $task): ?TaskProof
    {
        return $this->proofIn($task, $this->runs($task));
    }

    /** @param list<array<string, mixed>> $runs newest first */
    private function proofIn(Task $task, array $runs): ?TaskProof
    {
        $run = self::decidingRun($runs);
        if ($run === null || ($run['changed_during_run'] ?? []) !== []) {
            return null;
        }
        $id = $run['id'] ?? null;
        $at = $run['generated_at'] ?? null;
        if (!is_string($id) || !is_string($at)) {
            return null;
        }
        $tree = self::tree($run);
        if ($tree === []) {
            return null; // a run over no files checked nothing, so it proves nothing
        }
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
        if ($this->readRuns !== null) {
            return ($this->readRuns)($task);
        }

        return (new VerifyReceipts($this->projectRoot))->forTraces([$task->id, $task->traceId]);
    }

    /**
     * @param list<array<string, mixed>> $runs newest first
     * @return array<string, mixed>|null
     */
    private static function decidingRun(array $runs): ?array
    {
        $withoutCode = null;
        $covered = [];
        foreach ($runs as $run) {
            $tree = self::tree($run);
            if (($run['verdict'] ?? null) !== 'pass') {
                if (self::covers($covered, $tree)) {
                    continue;
                }

                return null;
            }
            if (!self::hasNothingToTest($tree)) {
                return $run;
            }
            $withoutCode ??= $run;
            if (($run['changed_during_run'] ?? []) === []) {
                $covered += $tree; // a pass whose files changed under it checked nothing it can vouch for
            }
        }

        return $withoutCode;
    }

    /**
     * The run that stands against closing, read the way decidingRun() reads for
     * a proof: a run that did not pass before any pass over code, newest first.
     *
     * @param list<array<string, mixed>> $runs newest first
     * @return array<string, mixed>|null
     */
    private static function failingRun(array $runs): ?array
    {
        $covered = [];
        foreach ($runs as $run) {
            $tree = self::tree($run);
            if (($run['verdict'] ?? null) !== 'pass') {
                if (self::covers($covered, $tree)) {
                    continue;
                }

                return $run;
            }
            if (!self::hasNothingToTest($tree)) {
                return null;
            }
            if (($run['changed_during_run'] ?? []) === []) {
                $covered += $tree; // a pass whose files changed under it checked nothing it can vouch for
            }
        }

        return null;
    }

    /**
     * Whether newer passes went over every file a run that did not pass was
     * over. Without this, a docs or config task whose check failed once, on
     * README.md say, never closed: the newer pass over the same file did not
     * count against the older fail (review, dev#284). A pass over docs never
     * covers a fail over code, since a pass over code ends both walks first.
     *
     * @param array<string, ?string> $covered the files of the newer passes over nothing to test
     * @param array<string, ?string> $tree the files of the run that did not pass
     */
    private static function covers(array $covered, array $tree): bool
    {
        return $covered !== [] && array_diff_key($tree, $covered) === [];
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
