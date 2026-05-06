<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\Event\EventListenerRegistry;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'dev:graph:project', description: 'Show project overview: modules, routes, contracts, listeners')]
final class DevGraphProjectCommand extends BaseCommand
{
    private ?AttributeDiscovery $attributeDiscovery;
    private ?EventListenerRegistry $eventListenerRegistry;
    private ?ModuleRegistry $moduleRegistry;
    private ?ClassDiscovery $classDiscovery = null;

    public function __construct(
        ?AttributeDiscovery $attributeDiscovery = null,
        ?EventListenerRegistry $eventListenerRegistry = null,
        ?ModuleRegistry $moduleRegistry = null,
    ) {
        $this->attributeDiscovery = $attributeDiscovery;
        $this->eventListenerRegistry = $eventListenerRegistry;
        $this->moduleRegistry = $moduleRegistry;
        parent::__construct('dev:graph:project');
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->attributeDiscovery()->initialize();
        $this->eventListenerRegistry()->ensureBuilt();
        $modules = $this->moduleRegistry()->getModules();
        $routes = $this->attributeDiscovery()->getRoutes();

        $routesByModule = [];
        foreach ($routes as $route) {
            $payloadClass = $route['class'] ?? '';
            $moduleName = $this->moduleRegistry()->getModuleNameForClass($payloadClass) ?? 'project';
            $routesByModule[$moduleName] = ($routesByModule[$moduleName] ?? 0) + 1;
        }

        $listenerClasses = $this->eventListenerRegistry()->getAllListenerClasses();
        $listenersByModule = [];
        foreach ($listenerClasses as $listenerClass) {
            $moduleName = $this->moduleRegistry()->getModuleNameForClass($listenerClass) ?? 'project';
            $listenersByModule[$moduleName] = ($listenersByModule[$moduleName] ?? 0) + 1;
        }

        $moduleDetails = [];
        foreach ($modules as $module) {
            $name = $module['name'];
            $type = $module['type'] ?? 'unknown';
            $extends = $module['extends'] ?? null;

            $sourceRoots = $this->resolveModuleSourceRoots($module);
            $counts = $this->countByCategory($sourceRoots);

            $detail = [
                'name' => $name,
                'type' => $type,
                'extends' => $extends,
                'namespace' => $module['namespace'] ?? null,
                'routes' => $routesByModule[$name] ?? 0,
                'listeners' => $listenersByModule[$name] ?? 0,
                'services' => $counts['services'],
                'contracts' => $counts['contracts'],
                'events' => $counts['events'],
                'models' => $counts['models'],
                'commands' => $counts['commands'],
            ];

            $moduleDetails[] = $detail;
        }

        $description = [
            'total_modules' => count($modules),
            'total_routes' => count($routes),
            'total_listeners' => count($listenerClasses),
            'modules' => $moduleDetails,
        ];

        if ($input->getOption('json')) {
            $topModuleName = $this->pickMostActiveModule($moduleDetails);
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.project-description/v1',
                'generated_at' => date('c'),
                'project' => $description,
                'next_command' => [
                    ['cmd' => 'ai:ask', 'args' => ['module', '--name=' . ($topModuleName ?? '<Module>'), '--json'], 'why' => 'drill into a module'],
                    ['cmd' => 'routes:list', 'args' => ['--json'], 'why' => 'full route surface (' . count($routes) . ' routes)'],
                    ['cmd' => 'contracts:list', 'args' => ['--json'], 'why' => 'interface → implementation map'],
                ],
            ], JSON_UNESCAPED_SLASHES));
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

    private function attributeDiscovery(): AttributeDiscovery
    {
        if ($this->attributeDiscovery === null) {
            $this->attributeDiscovery = new AttributeDiscovery(
                $this->classDiscovery(),
                $this->moduleRegistry(),
                new RouteRegistry(),
            );
        }

        return $this->attributeDiscovery;
    }

    private function eventListenerRegistry(): EventListenerRegistry
    {
        if ($this->eventListenerRegistry === null) {
            $this->eventListenerRegistry = new EventListenerRegistry(
                $this->classDiscovery(),
                $this->moduleRegistry(),
            );
        }

        return $this->eventListenerRegistry;
    }

    private function moduleRegistry(): ModuleRegistry
    {
        if ($this->moduleRegistry === null) {
            $this->moduleRegistry = new ModuleRegistry();
        }

        return $this->moduleRegistry;
    }

    private function classDiscovery(): ClassDiscovery
    {
        if ($this->classDiscovery === null) {
            $this->classDiscovery = new ClassDiscovery();
        }

        return $this->classDiscovery;
    }

    /**
     * @param list<array{name: string, routes: int, services: int, contracts: int, listeners: int, ...}> $modules
     */
    private function pickMostActiveModule(array $modules): ?string
    {
        $best = null;
        $bestScore = -1;
        foreach ($modules as $m) {
            $score = ($m['routes'] ?? 0) * 3
                + ($m['services'] ?? 0) * 2
                + ($m['contracts'] ?? 0)
                + ($m['listeners'] ?? 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $m['name'];
            }
        }
        return $best;
    }

    /**
     * Pick the directories to scan for a module. PSR-4 autoload roots are the
     * source of truth — they reflect what the module actually ships, regardless
     * of whether it lives in src/modules/, packages/, or vendor/. Falls back to
     * the module path itself when a composer.json declares no PSR-4 mapping.
     *
     * @param array<string, mixed> $module
     * @return list<string>
     */
    private function resolveModuleSourceRoots(array $module): array
    {
        $roots = [];
        $psr4 = $module['autoloadPsr4'] ?? [];
        if (is_array($psr4)) {
            foreach ($psr4 as $dirs) {
                foreach ((array) $dirs as $dir) {
                    if (is_string($dir) && is_dir($dir)) {
                        $roots[] = $dir;
                    }
                }
            }
        }

        if ($roots === []) {
            $path = $module['path'] ?? null;
            if (is_string($path) && is_dir($path)) {
                $roots[] = $path;
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Walk PHP files under each source root and bucket them by the closest
     * matching directory-name convention (Service, Contract, Event, Model,
     * Command). Console/Command files are CLI commands and are intentionally
     * excluded from the "commands" bucket — that bucket is for application/
     * domain commands.
     *
     * @param list<string> $sourceRoots
     * @return array{services: int, contracts: int, events: int, models: int, commands: int}
     */
    private function countByCategory(array $sourceRoots): array
    {
        $counts = ['services' => 0, 'contracts' => 0, 'events' => 0, 'models' => 0, 'commands' => 0];

        foreach ($sourceRoots as $root) {
            $realRoot = realpath($root) ?: $root;
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($realRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $absolute = $file->getPathname();
                $relative = ltrim(substr($absolute, strlen($realRoot)), '/\\');
                $segments = explode('/', str_replace('\\', '/', $relative));
                array_pop($segments);
                if ($segments === []) {
                    continue;
                }

                $hasConsoleAncestor = in_array('Console', $segments, true);

                foreach (array_reverse($segments) as $segment) {
                    if ($segment === 'Service') {
                        $counts['services']++;
                        break;
                    }
                    if ($segment === 'Contract') {
                        $counts['contracts']++;
                        break;
                    }
                    if ($segment === 'Event') {
                        $counts['events']++;
                        break;
                    }
                    if ($segment === 'Model') {
                        $counts['models']++;
                        break;
                    }
                    if ($segment === 'Command' && !$hasConsoleAncestor) {
                        $counts['commands']++;
                        break;
                    }
                }
            }
        }

        return $counts;
    }
}
