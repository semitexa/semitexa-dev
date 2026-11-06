<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Core\Environment;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskFiling;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * How far the work has come, counted from the backlog: done over approved, per
 * phase (ep-project-progress-from-backlog).
 *
 * Done means proven: a task closed with no proof stays done, is shown as
 * unproven and adds nothing to the percent.
 *
 * Zero tickets means two things: nobody looked, or someone looked and found
 * nothing. A fresh audit tells them apart, and is the one way a phase with no
 * tickets reads as anything but not measured. See {@see AuditRecord}.
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
    public const WITH_FRAMEWORK_ENV = 'SEMITEXA_STATE_WITH_FRAMEWORK';

    private const FRAMEWORK_REFS = ['packages/', 'vendor/semitexa/', 'Semitexa\\'];
    private const PROJECT_REFS = ['src/', 'Semitexa\\Modules\\'];

    /**
     * @param array<string, array{percent: ?int, done: int, approved: int, open: int, unproven: int}> $phases
     * @param array<string, AuditRecord> $audits the newest that speaks for each phase
     */
    private function __construct(
        public array $phases,
        public int $ideas,
        public int $noPhase,
        public bool $withFramework,
        public array $audits,
        private \DateTimeImmutable $now,
    ) {}

    /**
     * @param list<Task> $tasks
     * @param list<AuditRecord> $audits oldest first
     */
    public static function of(array $tasks, bool $withFramework, array $audits = [], ?\DateTimeImmutable $now = null): self
    {
        $done = $unproven = $approved = array_fill_keys(array_map(static fn (Phase $p): string => $p->value, Phase::cases()), 0);
        $ideas = 0;
        $noPhase = 0;
        foreach ($tasks as $task) {
            if ($task->status === TaskStatus::DISCARDED || !self::inScope($task, $withFramework)) {
                continue;
            }
            if (!self::counts($task)) {
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
            if ($task->status === TaskStatus::DONE) {
                $task->proof !== null ? $done[$phase->value]++ : $unproven[$phase->value]++;
            }
        }

        $phases = [];
        foreach ($approved as $phase => $total) {
            $phases[$phase] = [
                // Rounded down: 100 is said only when every task is done and proven.
                'percent' => $total === 0 ? null : intdiv($done[$phase] * 100, $total),
                'done' => $done[$phase],
                'approved' => $total,
                'open' => $total - $done[$phase] - $unproven[$phase],
                'unproven' => $unproven[$phase],
            ];
        }

        $newest = [];
        foreach ($audits as $audit) {
            // An audit of ./src alone says nothing about the framework.
            if ($audit->withFramework || !$withFramework) {
                $newest[$audit->phase->value] = $audit;
            }
        }

        return new self($phases, $ideas, $noPhase, $withFramework, $newest, $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
    }

    /** The option, from the command line or the project's .env. */
    public static function withFramework(bool $flag): bool
    {
        return $flag || filter_var(Environment::getEnvValue(self::WITH_FRAMEWORK_ENV, ''), FILTER_VALIDATE_BOOL);
    }

    public static function inScope(Task $task, bool $withFramework): bool
    {
        return $withFramework || !self::isFrameworkOnly($task);
    }

    /** Approved, or a bug: everything else is an idea. */
    public static function counts(Task $task): bool
    {
        return $task->isApproved() || TaskFiling::isBug($task->recipe);
    }

    /** "work 80% (8/10, 1 open, 1 unproven) · right not measured · fast 100% (3/3) · 4 ideas" */
    public function line(): string
    {
        $parts = [];
        foreach ($this->phases as $phase => $p) {
            $parts[] = $phase . ' ' . match (true) {
                $p['percent'] === null => $this->noTickets($phase),
                default => sprintf('%d%% (%s)', $p['percent'], implode(', ', array_filter([
                    $p['done'] . '/' . $p['approved'],
                    $p['open'] > 0 ? $p['open'] . ' open' : null,
                    $p['unproven'] > 0 ? $p['unproven'] . ' unproven' : null,
                ]))),
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

    private function noTickets(string $phase): string
    {
        $audit = $this->audits[$phase] ?? null;
        if ($audit === null) {
            return 'not measured';
        }
        $age = $audit->ageDays($this->now);
        $when = $age === 0 ? 'today' : $age . 'd ago';

        return $audit->isFresh($this->now) ? "nothing open (audited {$when})" : "not measured (last audit {$when})";
    }

    /** @return array{phases: array<string, array{percent: ?int, done: int, approved: int, open: int, unproven: int}>, ideas: int, approved_with_no_phase: int, with_framework: bool, audits: array<string, array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'phases' => $this->phases,
            'ideas' => $this->ideas,
            'approved_with_no_phase' => $this->noPhase,
            'with_framework' => $this->withFramework,
            'audits' => array_map(fn (AuditRecord $a): array => $a->toArray() + ['age_days' => $a->ageDays($this->now), 'fresh' => $a->isFresh($this->now)], $this->audits),
        ];
    }

    private static function isFrameworkOnly(Task $task): bool
    {
        $framework = false;
        foreach ($task->contextRefs as $ref) {
            $ref = str_starts_with($ref, './') ? substr($ref, 2) : $ref;
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
