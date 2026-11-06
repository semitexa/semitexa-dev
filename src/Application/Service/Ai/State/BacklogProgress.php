<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskFiling;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * How far the work has come, counted from the backlog: done over approved, per
 * phase (ep-project-progress-from-backlog).
 *
 * Nothing here is a judgement. Found a bug: it is one more ticket and the
 * percent moves by itself. A phase with no approved task is not measured, never
 * 0 and never 100. Ideas are counted apart and never enter a percent.
 *
 * A task under a bug recipe counts whether it was approved or not, so bugs
 * filed before approval existed are not missed, and one that became a bug
 * through an edit is counted too. A bug nobody wants is discarded.
 *
 * Without the framework only the project's own tasks count: one that points at
 * framework code and not at ./src is left out. A task that points at nothing
 * belongs to the project, and so does a project module's namespace.
 */
final readonly class BacklogProgress
{
    private const FRAMEWORK_REFS = ['packages/', 'vendor/semitexa/', 'Semitexa\\'];
    private const PROJECT_REFS = ['src/', 'Semitexa\\Modules\\'];

    /**
     * @param array<string, array{percent: ?int, done: int, approved: int, open: int}> $phases
     */
    private function __construct(
        public array $phases,
        public int $ideas,
        public int $noPhase,
        public bool $withFramework,
    ) {}

    /** @param list<Task> $tasks */
    public static function of(array $tasks, bool $withFramework): self
    {
        $done = $approved = array_fill_keys(array_map(static fn (Phase $p): string => $p->value, Phase::cases()), 0);
        $ideas = 0;
        $noPhase = 0;
        foreach ($tasks as $task) {
            if ($task->status === TaskStatus::DISCARDED || (!$withFramework && self::isFrameworkOnly($task))) {
                continue;
            }
            if (!$task->isApproved() && !TaskFiling::isBug($task->recipe)) {
                // Closed work nobody approved is history, not a proposal.
                $ideas += $task->status === TaskStatus::DONE ? 0 : 1;
                continue;
            }
            $phase = TaskFiling::phaseOf($task->phase, $task->recipe);
            if ($phase === null) {
                $noPhase++;
                continue;
            }
            $approved[$phase->value]++;
            $done[$phase->value] += $task->status === TaskStatus::DONE ? 1 : 0;
        }

        $phases = [];
        foreach ($approved as $phase => $total) {
            $phases[$phase] = [
                // Rounded down: 100 is said only when nothing is open.
                'percent' => $total === 0 ? null : intdiv($done[$phase] * 100, $total),
                'done' => $done[$phase],
                'approved' => $total,
                'open' => $total - $done[$phase],
            ];
        }

        return new self($phases, $ideas, $noPhase, $withFramework);
    }

    /** "work 80% (8/10, 2 open) · right not measured · fast 100% (3/3) · 4 ideas" */
    public function line(): string
    {
        $parts = [];
        foreach ($this->phases as $phase => $p) {
            $parts[] = $phase . ' ' . match (true) {
                $p['percent'] === null => 'not measured',
                $p['open'] === 0 => sprintf('%d%% (%d/%d)', $p['percent'], $p['done'], $p['approved']),
                default => sprintf('%d%% (%d/%d, %d open)', $p['percent'], $p['done'], $p['approved'], $p['open']),
            };
        }
        if ($this->ideas > 0) {
            $parts[] = $this->ideas . ($this->ideas === 1 ? ' idea' : ' ideas');
        }
        if ($this->noPhase > 0) {
            $parts[] = $this->noPhase . ' approved with no phase';
        }

        return implode(' · ', $parts);
    }

    /** @return array{phases: array<string, array{percent: ?int, done: int, approved: int, open: int}>, ideas: int, approved_with_no_phase: int, with_framework: bool} */
    public function toArray(): array
    {
        return [
            'phases' => $this->phases,
            'ideas' => $this->ideas,
            'approved_with_no_phase' => $this->noPhase,
            'with_framework' => $this->withFramework,
        ];
    }

    private static function isFrameworkOnly(Task $task): bool
    {
        $framework = false;
        foreach ($task->contextRefs as $ref) {
            foreach (self::PROJECT_REFS as $prefix) {
                if (str_starts_with($ref, $prefix)) {
                    return false;
                }
            }
            foreach (self::FRAMEWORK_REFS as $prefix) {
                $framework = $framework || str_starts_with($ref, $prefix);
            }
        }

        return $framework;
    }
}
