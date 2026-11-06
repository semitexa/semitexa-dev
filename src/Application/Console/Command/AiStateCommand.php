<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\State\AuditStore;
use Semitexa\Dev\Application\Service\Ai\State\BacklogProgress;
use Semitexa\Dev\Application\Service\Ai\Work\TaskStore;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The project's state: make it work · right · fast, counted from the backlog.
 *
 *   bin/semitexa ai:state                     done over approved per phase, for the code in ./src
 *   bin/semitexa ai:state --with-framework    the same with the framework's tasks
 *
 * SEMITEXA_STATE_WITH_FRAMEWORK=1 in .env turns the option on for good. Nobody
 * scores anything here: a number moves when a task is filed, approved or proven.
 */
#[AsCommand(name: 'ai:state', description: 'The project state, work · right · fast: done over approved tasks per phase, for ./src, with the framework on request')]
final class AiStateCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-state/v2';

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected AuditStore $auditStore;

    public function __construct()
    {
        parent::__construct('ai:state');
    }

    protected function configure(): void
    {
        $this
            ->addOption('with-framework', null, InputOption::VALUE_NONE, 'Count the framework\'s code as well as ./src (or ' . BacklogProgress::WITH_FRAMEWORK_ENV . '=1)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (the default when not interactive)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $progress = BacklogProgress::of($this->taskStore->list(strict: true), BacklogProgress::withFramework((bool) $input->getOption('with-framework')), $this->auditStore->all());
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            // A task file that does not read is a finding, never a number with a hole in it.
            $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
        if ($input->isInteractive() && !(bool) $input->getOption('json')) {
            $output->writeln(OutputFormatter::escape($progress->line()));

            return self::SUCCESS;
        }

        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'line' => $progress->line(), 'backlog' => $progress->toArray()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
