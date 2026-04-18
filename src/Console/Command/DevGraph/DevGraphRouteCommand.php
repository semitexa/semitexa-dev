<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Console\Command\Support\CommandDelegator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dev:graph:route', description: 'Developer view of route chain (alias of describe:route during Phase 5 migration)')]
final class DevGraphRouteCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:route');
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Route path (e.g., /pricing)')
            ->addOption('method', null, InputOption::VALUE_OPTIONAL, 'HTTP method (default: GET)', 'GET')
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
        return CommandDelegator::run($app, 'describe:route', $input, $output);
    }
}
