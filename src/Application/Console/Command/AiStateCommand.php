<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;
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
 *
 * The charter's signs of success are shown beside the numbers, never in them
 * (ep-project-progress-from-backlog): they say what the owner will judge the
 * project by, the percent says how far the approved work has come.
 */
#[AsCommand(name: 'ai:state', description: 'The project state, work · right · fast: done over approved tasks per phase, for ./src, with the framework on request')]
final class AiStateCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-state/v2';

    #[InjectAsReadonly]
    protected TaskStore $taskStore;

    #[InjectAsReadonly]
    protected AuditStore $auditStore;

    #[InjectAsReadonly]
    protected CharterStore $charterStore;

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
            $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $e->getMessage() . ': repair or remove it'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

            return self::FAILURE;
        }
        // A charter that does not read leaves the numbers standing: they come
        // from the backlog. It is said, never shown as "no signs".
        $charterError = null;
        $signs = [];
        try {
            $signs = $this->charterStore->get()->signs;
        } catch (\RuntimeException $e) {
            $charterError = $e->getMessage();
        }

        if ($input->isInteractive() && !(bool) $input->getOption('json')) {
            $output->writeln(OutputFormatter::escape($progress->line()));
            foreach ($this->signLines($signs, $charterError) as $line) {
                $output->writeln(OutputFormatter::escape($line));
            }

            return self::SUCCESS;
        }

        $envelope = [
            'artifact' => self::ARTIFACT,
            'line' => $progress->line(),
            'backlog' => $progress->toArray(),
            'signs' => array_map(static fn (SuccessSign $s): array => $s->toArray(), $signs),
        ];
        if ($charterError !== null) {
            $envelope['charter_error'] = $charterError;
        }
        $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param list<SuccessSign> $signs
     * @return list<string>
     */
    private function signLines(array $signs, ?string $charterError): array
    {
        if ($charterError !== null) {
            return ['signs of success unknown: ' . $charterError];
        }
        if ($signs === []) {
            return [];
        }
        $lines = ['signs of success (the charter\'s; not in the percent):'];
        foreach ($signs as $sign) {
            $lines[] = sprintf('  %s %s (%s)', $sign->id, $sign->text, $sign->isComputed() ? 'computed from ' . $sign->source : 'judged');
        }

        return $lines;
    }
}
