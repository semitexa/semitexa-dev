<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'describe:project', description: 'Show project overview: modules, routes, contracts, listeners')]
final class DescribeProjectCommand extends BaseCommand
{
    public function __construct(
        private readonly AttributeDiscovery $attributeDiscovery,
        private readonly EventListenerRegistry $eventListenerRegistry,
        private readonly ModuleRegistry $moduleRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->attributeDiscovery->initialize();
        $this->eventListenerRegistry->ensureBuilt();
        $modules = $this->moduleRegistry->getModules();
        $routes = $this->attributeDiscovery->getRoutes();

        // Count routes per module
        $routesByModule = [];
        foreach ($routes as $route) {
            $payloadClass = $route['class'] ?? '';
            $moduleName = $this->moduleRegistry->getModuleNameForClass($payloadClass) ?? 'project';
            $routesByModule[$moduleName] = ($routesByModule[$moduleName] ?? 0) + 1;
        }

        // Count listeners per module
        $listenerClasses = $this->eventListenerRegistry->getAllListenerClasses();
        $listenersByModule = [];
        foreach ($listenerClasses as $listenerClass) {
            $moduleName = $this->moduleRegistry->getModuleNameForClass($listenerClass) ?? 'project';
            $listenersByModule[$moduleName] = ($listenersByModule[$moduleName] ?? 0) + 1;
        }

        // Scan module directories for services, contracts, events, commands
        $root = $this->getProjectRoot();
        $moduleDetails = [];
        foreach ($modules as $module) {
            $name = $module['name'];
            $type = $module['type'] ?? 'unknown';
            $extends = $module['extends'] ?? null;

            $detail = [
                'name' => $name,
                'type' => $type,
                'extends' => $extends,
                'namespace' => $module['namespace'] ?? null,
                'routes' => $routesByModule[$name] ?? 0,
                'listeners' => $listenersByModule[$name] ?? 0,
            ];

            // For local modules, count domain files
            if ($type === 'local') {
                $modulePath = $root . '/src/modules/' . $name;
                $detail['services'] = $this->countPhpFiles($modulePath . '/Domain/Service');
                $detail['contracts'] = $this->countPhpFiles($modulePath . '/Domain/Contract');
                $detail['events'] = $this->countPhpFiles($modulePath . '/Domain/Event');
                $detail['models'] = $this->countPhpFiles($modulePath . '/Domain/Model');
                $detail['commands'] = $this->countPhpFiles($modulePath . '/Application/Command');
            }

            $moduleDetails[] = $detail;
        }

        $description = [
            'total_modules' => count($modules),
            'total_routes' => count($routes),
            'total_listeners' => count($listenerClasses),
            'modules' => $moduleDetails,
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.project-description/v1',
                'generated_at' => date('c'),
                'project' => $description,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->renderHuman(new SymfonyStyle($input, $output), $description);
        return Command::SUCCESS;
    }

    private function renderHuman(SymfonyStyle $io, array $info): void
    {
        $io->title('Project Overview');
        $io->text([
            "Modules:   {$info['total_modules']}",
            "Routes:    {$info['total_routes']}",
            "Listeners: {$info['total_listeners']}",
        ]);

        $io->section('Modules');

        $tableRows = [];
        foreach ($info['modules'] as $m) {
            $counts = [];
            if ($m['routes'] > 0) {
                $counts[] = "{$m['routes']}r";
            }
            if (isset($m['services']) && $m['services'] > 0) {
                $counts[] = "{$m['services']}s";
            }
            if (isset($m['contracts']) && $m['contracts'] > 0) {
                $counts[] = "{$m['contracts']}c";
            }
            if ($m['listeners'] > 0) {
                $counts[] = "{$m['listeners']}l";
            }
            if (isset($m['events']) && $m['events'] > 0) {
                $counts[] = "{$m['events']}e";
            }
            if (isset($m['commands']) && $m['commands'] > 0) {
                $counts[] = "{$m['commands']}cmd";
            }

            $tableRows[] = [
                $m['name'],
                $m['type'],
                $m['extends'] ?? '-',
                $counts ? implode(' ', $counts) : '-',
            ];
        }

        $io->table(['Module', 'Type', 'Extends', 'Contents (r=routes s=services c=contracts l=listeners e=events)'], $tableRows);
    }

    private function countPhpFiles(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $count++;
            }
        }

        return $count;
    }
}
