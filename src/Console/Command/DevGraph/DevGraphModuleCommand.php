<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Console\Command\Support\CommandDelegator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dev:graph:module', description: 'Developer view of module structure (alias of describe:module during Phase 5 migration)')]
final class DevGraphModuleCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:module');
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
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
        return CommandDelegator::run($app, 'describe:module', $input, $output);
    }
}
