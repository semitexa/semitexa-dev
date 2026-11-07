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
 *   empty    nothing to work on yet: interview the architect and fill the backlog.
 *            Filing what they say as it comes must not end the interview, so with
 *            questions open this stays the state until something is approved.
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
     * @param list<array{id: string, title: string, epic: string, status: string, phase: ?string, bug: bool, next_step: ?string, context_refs: list<string>}> $tasks what the state names: the task to take, the ones left, or the ideas
     */
    private function __construct(
        public string $state,
        public array $tasks,
        public int $total,
        private int $openQuestions = 0,
        private ?string $charterError = null,
        private bool $hasTasks = false,
        private ?Phase $only = null,
    ) {}

    /**
     * @param list<Task> $tasks the whole backlog
     * @param callable(string): bool $heldByAnother whether another live agent holds this task
     * @param string|null $mine the task this session already holds
     * @param int $openQuestions what the charter still needs from the architect
     * @param string|null $charterError why the charter cannot be read, which is no complete charter
     * @param Phase|null $only narrows the question to one phase: what is not in it is not this run's
     */
    public static function of(array $tasks, bool $withFramework, callable $heldByAnother, ?string $mine, int $openQuestions = 0, ?string $charterError = null, ?Phase $only = null): self
    {
        $inScope = array_values(array_filter($tasks, static fn (Task $t): bool => $t->status !== TaskStatus::DISCARDED && BacklogProgress::inScope($t, $withFramework)));
        if ($inScope === []) {
            // Not the same as nothing at all: say what the option would show.
            return new self(self::EMPTY, [], count(array_filter($tasks, static fn (Task $t): bool => $t->status !== TaskStatus::DISCARDED)), $openQuestions, $charterError);
        }

        $open = array_values(array_filter($inScope, static fn (Task $t): bool => $t->status !== TaskStatus::DONE));
        $approved = array_values(array_filter($open, static fn (Task $t): bool => BacklogProgress::counts($t) && ($only === null || $t->id === $mine || TaskFiling::phaseOf($t->phase, $t->recipe) === $only)));
        usort($approved, static fn (Task $a, Task $b): int => [self::rank($a, $mine), $a->createdAt, $a->id] <=> [self::rank($b, $mine), $b->createdAt, $b->id]);
        foreach ($approved as $task) {
            if ($task->status !== TaskStatus::BLOCKED && !$heldByAnother($task->id)) {
                return new self(self::WORK, [self::row($task)], count($approved));
            }
        }
        if ($approved !== []) {
            return self::listing(self::WAITING, $approved);
        }
        // Only before the first approval: a project that has done its work is done,
        // whatever its charter still lacks.
        if ($open !== [] && ($openQuestions > 0 || $charterError !== null) && array_filter($inScope, BacklogProgress::counts(...)) === []) {
            return new self(self::EMPTY, [], 0, $openQuestions, $charterError, true);
        }
        if ($only !== null) {
            return new self(self::DONE, [], 0, only: $only);
        }

        $ideas = array_values(array_filter($open, static fn (Task $t): bool => !BacklogProgress::counts($t)));

        if ($ideas === []) {
            return new self(self::DONE, [], 0);
        }
        $listed = self::listing(self::IDEAS, $ideas);

        // Approved by someone, not only counted: a closed legacy bug counts and approved nothing.
        return new self(self::IDEAS, $listed->tasks, $listed->total, hasTasks: array_filter($inScope, static fn (Task $t): bool => $t->isApproved()) !== []);
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

    /** @return array{id: string, title: string, epic: string, status: string, phase: ?string, bug: bool, next_step: ?string, context_refs: list<string>} */
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
            'context_refs' => $task->contextRefs,
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
            self::EMPTY => match (true) {
                $this->charterError !== null => 'The project charter cannot be read, and it comes before any work.',
                $this->hasTasks => sprintf('The charter is not finished: %d questions are open. Finish the interview before any work.', $this->openQuestions),
                $this->openQuestions > 0 => 'The backlog is empty. Before any code, find out what this project is: interview the architect.',
                default => 'The backlog is empty and the charter is complete: find out what must work first.',
            } . ($this->total > 0 ? sprintf(' %d framework tasks are left out: --with-framework counts them.', $this->total) : ''),
            self::WORK => sprintf('Taking %s: %s (%d open).', $this->tasks[0]['id'], $this->tasks[0]['title'], $this->total),
            self::WAITING => sprintf('Nothing to take now: %d open, all blocked, set aside or held by another agent. Show the architect what they wait for.', $this->total),
            self::IDEAS => sprintf($this->hasTasks ? 'Everything approved is closed. %d ideas are waiting: offer them and ask which to approve.' : 'Nothing is approved yet. %d ideas are waiting: offer them and ask which to approve.', $this->total),
            default => $this->only !== null
                ? "Nothing approved is open under {$this->only->value}."
                : 'Everything is done. Are there any new ideas?',
        };
    }

    /**
     * @param string $command how this agent runs ai:make-it again
     * @return list<string>
     */
    public function steps(string $command): array
    {
        return match ($this->state) {
            self::EMPTY => $this->charterError !== null ? ["The project charter cannot be read ({$this->charterError}). Show the architect and fix or remove it, then run {$command} again."] : [
                $this->openQuestions > 0
                    ? "Ask the architect the question under interview, in their language and your own words, and record the answer as it says. {$this->openQuestions} left; run {$command} after each answer for the next one. They can stop at any point: it resumes where it stopped."
                    : 'The charter is complete. Ask the architect what must work first, and what a user should be able to do on day one; offer what the goal and the signs of success suggest (bin/semitexa ai:charter show has them).',
                'As the answers come, file them: bin/semitexa ai:epic start --id=<id> --title="<what>" --goal="<why>", then bin/semitexa ai:work start --id=<id> --epic=<epic> --title="<what>" --phase=work for each piece of work. If the architect has a spec, cut it into tasks the same way, each small enough to prove with a test.',
                'Show the architect the list. On their yes: bin/semitexa ai:work:approve --epic=<id> --words="<what they said>".',
                'Run ' . $command . ' again.',
            ],
            self::WORK => self::stepsFor($this->tasks[0]['id'], $command),
            self::WAITING => ['Commit var/ai-work if it changed. Name each task and what it waits for, then stop: a question for the architect is in its next step.'],
            self::IDEAS => ['Commit var/ai-work if it changed. Offer them and take none. On a yes: bin/semitexa ai:work:approve --id=<id> --words="<what they said>", then run ' . $command . ' again.'],
            default => [$this->only !== null ? 'Commit var/ai-work if it changed, then stop here, or find what this phase owes and file it.' : 'Commit var/ai-work if it changed, then stop here. A new idea goes in with ai:work start; on a yes, approve it and run ' . $command . ' again.'],
        };
    }

    /** @return list<string> */
    private static function stepsFor(string $id, string $command): array
    {
        return [
            "Claim it: bin/semitexa ai:work update --id={$id} --status=in_progress.",
            'Do it, with a test: a task counts as done only when a test proves it. Docs and config need none.',
            'Found a bug or debt off your path? File it and carry on: bin/semitexa ai:work start --id=<id> --epic=<epic> --title="<what>" --context-ref=<path> with --recipe=fix_bug, or --phase=right, or --phase=fast. File what this task showed you; do not go looking for more.',
            'If it shows on a page, open that page in a browser and look at it, in light and dark, with a screenshot if you cannot: a passing test does not see what a person sees. If it looks broken, fix it, or block the task, before going on.',
            "Run bin/semitexa ai:verify --trace={$id}. Three failures in a row, or a decision that is the architect's: bin/semitexa ai:work update --id={$id} --status=blocked --next-step=\"<what it needs>\", and go to the last step.",
            "Commit this task alone, with a line Task: {$id}. Do not push unless the project's charter says to.",
            "Close it: bin/semitexa ai:work update --id={$id} --status=done, then commit var/ai-work, so the backlog travels with the code in its final state (unless the project's .gitignore keeps it out).",
            'Run ' . $command . ' again and do what it says. Stop only when it says go_on: false.',
        ];
    }
}
