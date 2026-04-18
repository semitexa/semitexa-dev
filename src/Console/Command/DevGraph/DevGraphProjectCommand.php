<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Console\Command\Support\CommandDelegator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dev:graph:project', description: 'Developer view of project overview (alias of describe:project during Phase 5 migration)')]
final class DevGraphProjectCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:project');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        if ($app === null) {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => 'Application not available',
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }
        return CommandDelegator::run($app, 'describe:project', $input, $output);
    }
}
