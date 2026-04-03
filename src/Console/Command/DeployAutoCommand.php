<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Deployment\Service\FrameworkDeploymentExecutor;
use Semitexa\Dev\Deployment\Service\FrameworkDeploymentPlanner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'deploy:auto', description: 'Run automatic Semitexa framework deployment when enabled and updates are available')]
final class DeployAutoCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output deployment result as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->getProjectRoot();
        $planner = new FrameworkDeploymentPlanner();
        $executor = new FrameworkDeploymentExecutor();

        $plan = $planner->plan($projectRoot);
        $result = $executor->execute($projectRoot, $plan);

        if ($input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title('Semitexa Automatic Deployment');
        $io->definitionList(
            ['Status' => (string) $result['status']],
            ['Reason' => (string) $result['reason']],
            ['Selected version' => (string) ($result['selected_version'] ?? 'none')],
            ['Source mode' => (string) ($result['source_mode'] ?? 'unknown')],
            ['Release channel' => (string) ($result['release_channel'] ?? 'unknown')],
        );

        if (($result['restart_status'] ?? null) !== null) {
            $io->text('Restart: ' . $result['restart_status']);
        }

        return $result['status'] === 'failed' ? Command::FAILURE : Command::SUCCESS;
    }
}
