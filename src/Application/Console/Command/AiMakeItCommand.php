<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Semitexa\Dev\Application\Service\Ai\State\AuditRecord;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Application\Service\Ai\State\BacklogProgress;
use Semitexa\Dev\Application\Service\Ai\State\MakeItPlan;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStatus;
use Semitexa\Dev\Application\Service\Ai\Work\EpicStore;
use Semitexa\Dev\Application\Service\Ai\Work\Task;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * What an agent told "make it" does next, from the state of the backlog.
 *
 *   bin/semitexa ai:make-it                    for the project's own code in ./src
 *   bin/semitexa ai:make-it --with-framework   the framework's tasks as well
 *
 * Run it, do what it says, and run it again while go_on is true: that is the
 * whole loop, and any agent with a shell can walk it.
 */
#[AsCommand(name: 'ai:make-it', description: 'What to do next in this project: interview on an empty backlog, the next approved task with its steps, ideas to offer, or all done')]
final class AiMakeItCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-make-it/v1';

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected EpicStore $epicStore;

    #[InjectAsReadonly]
    protected AuditStore $auditStore;

    public function __construct()
    {
        parent::__construct('ai:make-it');
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-framework', null, InputOption::VALUE_NONE, 'Count the framework\'s tasks as well as ./src (or ' . BacklogProgress::WITH_FRAMEWORK_ENV . '=1)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (the default when not interactive)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $withFramework = BacklogProgress::withFramework((bool) $input->getOption('with-framework'));
        $tasks = $this->liveTasks();
        $registry = new AgentRegistry(ProjectRoot::get());
        $self = $registry->current();
        $plan = MakeItPlan::of(
            $tasks,
            $withFramework,
            static fn (string $id): bool => $registry->holderOf($id, $self?->id) !== null,
            $self?->task !== null && $self->holds($self->task, $registry->project()) ? $self->task : null,
        );
        $steps = $plan->steps('bin/semitexa ai:make-it' . ($withFramework ? ' --with-framework' : ''));
        $line = BacklogProgress::of($tasks, $withFramework, $this->audits())->line();

        if ($input->isInteractive() && !(bool) $input->getOption('json')) {
            return $this->print($output, $plan, $line, $steps);
        }

        $output->writeln(json_encode([
            'artifact' => self::ARTIFACT,
            'state' => $plan->state,
            'go_on' => $plan->goOn(),
            'say' => $plan->say(),
            'line' => $line,
            'tasks' => $plan->tasks,
            'total' => $plan->total,
            'steps' => $steps,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * The line beside the answer: an audit file that cannot be read must not
     * stop a loop that has tasks to take.
     *
     * @return list<AuditRecord>
     */
    private function audits(): array
    {
        try {
            return $this->auditStore->all();
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * A task of a closed epic is not work to take, and one whose epic is gone is
     * hidden from every listing that starts at an epic.
     *
     * @return list<Task>
     */
    private function liveTasks(): array
    {
        $live = [];
        foreach ($this->epicStore->list() as $epic) {
            $live[$epic->id] = !in_array($epic->status, [EpicStatus::ARCHIVED, EpicStatus::DISCARDED], true);
        }

        return array_values(array_filter($this->taskStore->list(), static fn (Task $task): bool => $live[$task->epicId] ?? false));
    }

    /**
     * An agent's shell can look interactive through a pipe, so what the loop
     * turns on is printed here as well, never only in the JSON.
     *
     * @param list<string> $steps
     */
    private function print(OutputInterface $output, MakeItPlan $plan, string $line, array $steps): int
    {
        $text = [$plan->say(), $line];
        foreach ($plan->tasks as $task) {
            $text[] = sprintf('  %s: %s', $task['id'], $task['title']);
            if ($task['next_step'] !== null) {
                $text[] = '    next: ' . $task['next_step'];
            }
        }
        foreach ($steps as $number => $step) {
            $text[] = sprintf('%d. %s', $number + 1, $step);
        }
        $text[] = sprintf('state: %s, go_on: %s', $plan->state, $plan->goOn() ? 'true' : 'false');
        foreach ($text as $row) {
            $output->writeln(OutputFormatter::escape((str_starts_with($row, '  ') ? substr($row, 0, strspn($row, ' ')) : '') . self::oneLine($row)));
        }

        return self::SUCCESS;
    }

    /**
     * Whitespace collapsed to single spaces; control characters (ESC and the
     * rest of Cc) and the bidirectional overrides that can reorder a line on
     * screen dropped. Other format characters stay: a zero-width joiner is
     * part of an emoji or a script, not a terminal command.
     */
    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/[\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u', '', (string) preg_replace('/\s+/u', ' ', $text)));
    }
}
