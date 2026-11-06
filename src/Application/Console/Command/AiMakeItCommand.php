<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Application\Service\Ai\Presence\AgentRegistry;
use Semitexa\Dev\Application\Service\Ai\State\BacklogProgress;
use Semitexa\Dev\Application\Service\Ai\State\MakeItPlan;
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
        $flag = (bool) $input->getOption('with-framework');
        $withFramework = BacklogProgress::withFramework($flag);
        $tasks = $this->taskStore->list();
        $registry = new AgentRegistry(ProjectRoot::get());
        $self = $registry->current();
        $plan = MakeItPlan::of(
            $tasks,
            $withFramework,
            static fn (string $id): bool => $registry->holderOf($id, $self?->id) !== null,
            $self?->task,
        );
        $steps = $plan->steps('bin/semitexa ai:make-it' . ($flag ? ' --with-framework' : ''));
        $line = BacklogProgress::of($tasks, $withFramework)->line();

        if ($input->isInteractive() && !(bool) $input->getOption('json')) {
            $output->writeln(OutputFormatter::escape($plan->say()));
            $output->writeln(OutputFormatter::escape($line));
            foreach ($plan->tasks as $task) {
                $output->writeln(OutputFormatter::escape(sprintf('  %s: %s', $task['id'], $task['title'])));
            }
            foreach ($steps as $number => $step) {
                $output->writeln(OutputFormatter::escape(sprintf('%d. %s', $number + 1, $step)));
            }

            return self::SUCCESS;
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
}
