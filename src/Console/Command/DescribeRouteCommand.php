<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Core\Discovery\AttributeDiscovery;
use Semitexa\Core\Discovery\ClassDiscovery;
use Semitexa\Core\Discovery\RouteRegistry;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'describe:route', description: 'Show the full chain for a route: payload → handler → resource → template → auth')]
final class DescribeRouteCommand extends BaseCommand
{
    private ?AttributeDiscovery $attributeDiscovery;
    private ?ModuleRegistry $moduleRegistry;
    private ?ClassDiscovery $classDiscovery = null;

    public function __construct(
        ?AttributeDiscovery $attributeDiscovery = null,
        ?ModuleRegistry $moduleRegistry = null,
    ) {
        $this->attributeDiscovery = $attributeDiscovery;
        $this->moduleRegistry = $moduleRegistry;
        parent::__construct();
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
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('path')) {
            $io->error('Missing required option: --path');
            return Command::FAILURE;
        }

        $this->attributeDiscovery()->initialize();

        $path = $input->getOption('path');
        $method = strtoupper($input->getOption('method') ?? 'GET');

        $route = $this->attributeDiscovery()->findRoute($path, $method);

        if ($route === null) {
            // Try matching against all routes by path prefix
            $routes = $this->attributeDiscovery()->getRoutes();
            foreach ($routes as $r) {
                if (($r['path'] ?? '') === $path) {
                    $methods = $r['methods'] ?? [$r['method'] ?? 'GET'];
                    $route = $this->attributeDiscovery()->findRoute($path, $methods[0]);
                    break;
                }
            }
        }

        if ($route === null) {
            $io->error("Route not found: {$method} {$path}");
            return Command::FAILURE;
        }

        $description = $this->buildDescription($route);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.route-description/v1',
                'generated_at' => date('c'),
                'route' => $description,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->renderHuman($io, $description);
        return Command::SUCCESS;
    }

    private function buildDescription(array $route): array
    {
        $payloadClass = $route['class'] ?? '';
        $methods = $route['methods'] ?? [$route['method'] ?? 'GET'];
        $responseClass = $route['responseClass'] ?? null;
        $handlers = $route['handlers'] ?? [];
        $responseAttrs = $responseClass ? $this->attributeDiscovery()->getResolvedResponseAttributes($responseClass) : null;

        $description = [
            'path' => $route['path'] ?? '',
            'methods' => $methods,
            'name' => $route['name'] ?? null,
            'public' => $route['public'] ?? true,
            'payload' => [
                'class' => $payloadClass,
                'module' => $this->moduleRegistry()->getModuleNameForClass($payloadClass) ?? 'project',
                'file' => $this->resolveRelativeFile($payloadClass),
            ],
            'resource' => null,
            'handlers' => [],
            'template' => null,
        ];

        if ($responseClass) {
            $description['resource'] = [
                'class' => $responseClass,
                'module' => $this->moduleRegistry()->getModuleNameForClass($responseClass) ?? 'project',
                'file' => $this->resolveRelativeFile($responseClass),
                'handle' => $responseAttrs['handle'] ?? null,
                'template' => $responseAttrs['template'] ?? null,
            ];

            if (!empty($responseAttrs['template'])) {
                $description['template'] = $responseAttrs['template'];
            }
        }

        usort($handlers, fn ($a, $b) => ($b['priority'] ?? 0) <=> ($a['priority'] ?? 0));
        foreach ($handlers as $h) {
            $description['handlers'][] = [
                'class' => $h['class'],
                'module' => $this->moduleRegistry()->getModuleNameForClass($h['class']) ?? 'project',
                'file' => $this->resolveRelativeFile($h['class']),
                'execution' => $h['execution'] ?? 'sync',
                'priority' => $h['priority'] ?? 0,
            ];
        }

        return $description;
    }

    private function renderHuman(SymfonyStyle $io, array $info): void
    {
        $methodStr = implode('|', $info['methods']);
        $io->title("{$methodStr} {$info['path']}");

        $authLabel = $info['public'] ? 'Public (no auth required)' : 'Protected (auth required)';
        $io->text("Auth: {$authLabel}");
        if ($info['name']) {
            $io->text("Name: {$info['name']}");
        }
        $io->newLine();

        $io->section('Chain');

        // Payload
        $io->text("  Payload:  {$info['payload']['class']}");
        $io->text("            {$info['payload']['file']}");

        // Handlers
        foreach ($info['handlers'] as $h) {
            $io->text("  Handler:  {$h['class']} [{$h['execution']}]");
            $io->text("            {$h['file']}");
        }

        // Resource
        if ($info['resource']) {
            $io->text("  Resource: {$info['resource']['class']}");
            $io->text("            {$info['resource']['file']}");
        }

        // Template
        if ($info['template']) {
            $io->text("  Template: {$info['template']}");
        }
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

    private function resolveRelativeFile(string $className): ?string
    {
        try {
            $file = (new \ReflectionClass($className))->getFileName();
            if ($file === false) {
                return null;
            }
            $root = $this->getProjectRoot();
            if (str_starts_with($file, $root)) {
                return ltrim(substr($file, strlen($root)), '/');
            }
            return $file;
        } catch (\Throwable) {
            return null;
        }
    }
}
