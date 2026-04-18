<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Console\Command\Support\CommandDelegator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dev:graph:mine-conventions', description: 'Mine per-module conventions cache (alias of ai:mine-conventions during Phase 5 migration)')]
final class DevGraphMineConventionsCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:mine-conventions');
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mine but do not persist')
            ->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Restrict NDJSON to a single module');
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
        return CommandDelegator::run($app, 'ai:mine-conventions', $input, $output);
    }
}
