<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Convention\ConventionMiner;
use Semitexa\Dev\Application\Service\Ai\Convention\ConventionStore;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'dev:graph:mine-conventions', description: 'Walk the codebase and persist per-module conventions used by convention-aware scaffolders')]
final class DevGraphMineConventionsCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('dev:graph:mine-conventions');
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Mine but do not persist; emits NDJSON of what would be written')
            ->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Restrict NDJSON output to a single module');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $miner = new ConventionMiner($this->getProjectRoot());
        $store = new ConventionStore($this->getProjectRoot());
        $byModule = $miner->mineAll();

        $moduleFilter = $input->getOption('module');
        $filtered = [];
        foreach ($byModule as $module => $conv) {
            if ($moduleFilter !== null && $module !== $moduleFilter) {
                continue;
            }
            $filtered[$module] = $conv;
        }

        $totalInjections = 0;
        foreach ($filtered as $conv) {
            $totalInjections += count($conv->handler_injections);
        }

        $output->writeln(json_encode([
            'kind'              => 'summary',
            'modules_mined'     => count($filtered),
            'injections_total'  => $totalInjections,
            'dry_run'           => (bool) $input->getOption('dry-run'),
            'cache_path'        => $store->path(),
        ], JSON_UNESCAPED_SLASHES));

        foreach ($filtered as $module => $conv) {
            $recurring = $conv->recurringHandlerInjections();
            $output->writeln(json_encode([
                'kind'                => 'module',
                'module'              => $module,
                'handlers_sampled'    => $conv->handlers_sampled,
                'injection_count'     => count($conv->handler_injections),
                'recurring_injections' => count($recurring),
            ], JSON_UNESCAPED_SLASHES));

            foreach (array_slice($recurring, 0, 5) as $hi) {
                $output->writeln(json_encode([
                    'kind'      => 'injection',
                    'module'    => $module,
                    'attribute' => $hi->attribute,
                    'type'      => $hi->type,
                    'property'  => $hi->propertyName,
                    'frequency' => $hi->frequency,
                ], JSON_UNESCAPED_SLASHES));
            }
        }

        if (!$input->getOption('dry-run')) {
            $store->writeAll($byModule);
            $output->writeln(json_encode([
                'kind'   => 'persisted',
                'path'   => $store->path(),
                'action' => 'none',
            ], JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
