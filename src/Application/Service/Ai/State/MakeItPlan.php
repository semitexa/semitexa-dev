<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskFiling;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStatus;

/**
 * What "make it" means right now, read from the backlog (ep-make-it-for-every-agent).
 *
 * The choice lives here and not in a skill's text, so every agent, in every
 * runtime, gets the same answer:
 *
 *   empty    nothing in the backlog: interview the architect and fill it
 *   work     an approved task is open: here it is, and how to take it through
 *   waiting  what is open is blocked or another agent's: nothing to take now
 *   ideas    nothing approved is open, ideas are: offer them, take none
 *   done     everything is closed: say so and ask for new ideas
 *
 * Only `work` tells an agent to come back, so a loop ends by itself.
 */
final readonly class MakeItPlan
{
    public const EMPTY = 'empty';
    public const WORK = 'work';
    public const WAITING = 'waiting';
    public const IDEAS = 'ideas';
    public const DONE = 'done';

    private const LISTED = 10;

    /**
     * @param list<array{id: string, title: string, epic: string, status: string, phase: ?string, bug: bool, next_step: ?string}> $tasks what the state names: the task to take, the ones left, or the ideas
     */
    private function __construct(
        public string $state,
        public array $tasks,
        public int $total,
    ) {}

    /**
     * @param list<Task> $tasks the whole backlog
     * @param callable(string): bool $heldByAnother whether another live agent holds this task
     * @param string|null $mine the task this session already holds
     */
    public static function of(array $tasks, bool $withFramework, callable $heldByAnother, ?string $mine): self
    {
        $inScope = array_values(array_filter($tasks, static fn (Task $t): bool => $t->status !== TaskStatus::DISCARDED && BacklogProgress::inScope($t, $withFramework)));
        if ($inScope === []) {
            // Not the same as nothing at all: say what the option would show.
            return new self(self::EMPTY, [], count(array_filter($tasks, static fn (Task $t): bool => $t->status !== TaskStatus::DISCARDED)));
        }

        $open = array_values(array_filter($inScope, static fn (Task $t): bool => $t->status !== TaskStatus::DONE));
        $approved = array_values(array_filter($open, BacklogProgress::counts(...)));
        usort($approved, static fn (Task $a, Task $b): int => [self::rank($a, $mine), $a->createdAt, $a->id] <=> [self::rank($b, $mine), $b->createdAt, $b->id]);
        foreach ($approved as $task) {
            if ($task->status !== TaskStatus::BLOCKED && !$heldByAnother($task->id)) {
                return new self(self::WORK, [self::row($task)], count($approved));
            }
        }
        if ($approved !== []) {
            return self::listing(self::WAITING, $approved);
        }

        $ideas = array_values(array_filter($open, static fn (Task $t): bool => !BacklogProgress::counts($t)));

        return $ideas === [] ? new self(self::DONE, [], 0) : self::listing(self::IDEAS, $ideas);
    }

    /** Bugs first, then work, right, fast; a task with no phase last. What this session already holds comes before all of them. */
    private static function rank(Task $task, ?string $mine): int
    {
        return match (true) {
            $task->id === $mine => 0,
            TaskFiling::isBug($task->recipe) => 1,
            $task->phase === Phase::WORK => 2,
            $task->phase === Phase::RIGHT => 3,
            $task->phase === Phase::FAST => 4,
            default => 5,
        };
    }

    /** @param list<Task> $tasks */
    private static function listing(string $state, array $tasks): self
    {
        return new self($state, array_map(self::row(...), array_slice($tasks, 0, self::LISTED)), count($tasks));
    }

    /** @return array{id: string, title: string, epic: string, status: string, phase: ?string, bug: bool, next_step: ?string} */
    private static function row(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'epic' => $task->epicId,
            'status' => $task->status->value,
            'phase' => TaskFiling::phaseOf($task->phase, $task->recipe)?->value,
            'bug' => TaskFiling::isBug($task->recipe),
            'next_step' => $task->nextStep,
        ];
    }

    /** Only here is there more to do without the architect. */
    public function goOn(): bool
    {
        return $this->state === self::WORK;
    }

    /** What to tell the architect, for the agent to put in their language. */
    public function say(): string
    {
        return match ($this->state) {
            self::EMPTY => 'The backlog is empty. Before any code, find out what this project is: interview the architect.'
                . ($this->total > 0 ? sprintf(' %d framework tasks are left out: --with-framework counts them.', $this->total) : ''),
            self::WORK => sprintf('Taking %s: %s (%d open).', $this->tasks[0]['id'], $this->tasks[0]['title'], $this->total),
            self::WAITING => sprintf('Nothing to take now: %d open, all blocked or held by another agent. Show the architect what they wait for.', $this->total),
            self::IDEAS => sprintf('Everything approved is closed. %d ideas are waiting: offer them and ask which to approve.', $this->total),
            default => 'Everything is done. Are there any new ideas?',
        };
    }

    /**
     * @param string $command how this agent runs ai:make-it again
     * @return list<string>
     */
    public function steps(string $command): array
    {
        return match ($this->state) {
            self::EMPTY => [
                'Ask the architect one question at a time: bin/semitexa ai:charter next tells you which, ai:charter answer records the reply. Repeat until it has no more.',
                'As the answers come, file them: bin/semitexa ai:epic start --id=<id> --title="<what>" --goal="<why>", then bin/semitexa ai:work start --id=<id> --epic=<epic> --title="<what>" --phase=work for each piece of work.',
                'Show the architect the list. On their yes: bin/semitexa ai:work:approve --epic=<id> --words="<what they said>".',
                'Run ' . $command . ' again.',
            ],
            self::WORK => self::stepsFor($this->tasks[0]['id'], $command),
            self::WAITING => ['Name each task and what it waits for, then stop: a question for the architect is in its next step.'],
            self::IDEAS => ['Offer them and take none. On a yes: bin/semitexa ai:work:approve --id=<id> --words="<what they said>", then run ' . $command . ' again.'],
            default => ['Stop here. A new idea goes in with ai:work start; on a yes, approve it and run ' . $command . ' again.'],
        };
    }

    /** @return list<string> */
    private static function stepsFor(string $id, string $command): array
    {
        return [
            "Claim it: bin/semitexa ai:work update --id={$id} --status=in_progress.",
            'Do it, with a test: a task counts as done only when a test proves it. Docs and config need none.',
            'Found a bug or debt off your path? File it and carry on: bin/semitexa ai:work start --id=<id> --epic=<epic> --title="<what>" with --recipe=fix_bug, or --phase=right, or --phase=fast. File what this task showed you; do not go looking for more.',
            "Run bin/semitexa ai:verify --trace={$id}. Three failures in a row, or a decision that is the architect's: bin/semitexa ai:work update --id={$id} --status=blocked --next-step=\"<what it needs>\", and go to the last step.",
            "Commit this task alone, with a last line: Task: {$id}. Do not push unless the project's charter says to.",
            "Close it: bin/semitexa ai:work update --id={$id} --status=done.",
            'Run ' . $command . ' again and do what it says. Stop only when it says go_on: false.',
        ];
    }
}
