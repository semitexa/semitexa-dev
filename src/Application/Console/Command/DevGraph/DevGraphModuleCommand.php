<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command\DevGraph;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Core\ModuleRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'dev:graph:module', description: 'Show module structure: routes, handlers, services, contracts, listeners')]
final class DevGraphModuleCommand extends BaseCommand
{
    private ?ModuleRegistry $moduleRegistry;

    public function __construct(?ModuleRegistry $moduleRegistry = null)
    {
        $this->moduleRegistry = $moduleRegistry;
        parent::__construct('dev:graph:module');
    }

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name (registry name, alias, or short form)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $requested = (string) ($input->getOption('name') ?? '');
        if ($requested === '') {
            $io->error('Missing required option: --name');
            return self::FAILURE;
        }

        $module = $this->resolveModule($requested);
        if ($module === null) {
            $available = $this->knownModuleNames();
            $io->error("Module not found: {$requested}");
            $io->text('Known modules: ' . ($available === [] ? '(none)' : implode(', ', $available)));
            return self::FAILURE;
        }

        $sourceRoots = $this->resolveSourceRoots($module);
        $buckets = $this->scanByCategory($sourceRoots);

        $templates = [];
        foreach ($module['templatePaths'] ?? [] as $templateDir) {
            foreach ($this->scanFiles((string) $templateDir, 'twig') as $file) {
                $templates[] = $file;
            }
        }
        sort($templates);
        $templates = array_values(array_unique($templates));

        $description = [
            'module' => $module['name'],
            'aliases' => $module['aliases'] ?? [],
            'type' => $module['type'] ?? 'unknown',
            'namespace' => $module['namespace'] ?? null,
            'path' => $module['path'] ?? null,
            'payloads' => $buckets['payloads'],
            'handlers' => $buckets['handlers'],
            'resources' => $buckets['resources'],
            'services' => $buckets['services'],
            'contracts' => $buckets['contracts'],
            'events' => $buckets['events'],
            'models' => $buckets['models'],
            'listeners' => $buckets['listeners'],
            'commands' => $buckets['commands'],
            'templates' => $templates,
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.module-description/v1',
                'generated_at' => date('c'),
                'module' => $description,
                'next_command' => $this->buildNextCommands($description),
            ], JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $io->title("Module: {$description['module']}");
        $io->text("Namespace: " . ($description['namespace'] ?? '(unknown)'));
        $io->text("Path: " . ($description['path'] ?? '(unknown)'));
        if ($description['aliases'] !== []) {
            $io->text('Aliases: ' . implode(', ', $description['aliases']));
        }
        $io->newLine();

        $sections = [
            'Payloads' => $description['payloads'],
            'Handlers' => $description['handlers'],
            'Resources' => $description['resources'],
            'Services' => $description['services'],
            'Contracts' => $description['contracts'],
            'Events' => $description['events'],
            'Models' => $description['models'],
            'Listeners' => $description['listeners'],
            'Commands' => $description['commands'],
            'Templates' => $description['templates'],
        ];

        foreach ($sections as $label => $files) {
            if ($files === []) {
                continue;
            }
            $io->section("{$label} (" . count($files) . ')');
            foreach ($files as $file) {
                $io->text('  ' . $file);
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $description
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildNextCommands(array $description): array
    {
        $moduleName = (string) $description['module'];
        $out = [];

        $handlers = $description['handlers'] ?? [];
        if (is_array($handlers) && $handlers !== []) {
            $out[] = [
                'cmd'  => 'ai:ask',
                'args' => ['route', '--path=<path>', '--json'],
                'why'  => 'inspect one endpoint end-to-end (payload → handler → resource)',
            ];
        }

        $contracts = $description['contracts'] ?? [];
        if (is_array($contracts) && $contracts !== []) {
            $out[] = [
                'cmd'  => 'contracts:list',
                'args' => ['--json'],
                'why'  => 'see which implementations satisfy this module\'s contracts',
            ];
        }

        $events = $description['events'] ?? [];
        if (is_array($events) && $events !== []) {
            $out[] = [
                'cmd'  => 'ai:ask',
                'args' => ['event', '--json'],
                'why'  => 'list events and their listeners',
            ];
        }

        $out[] = [
            'cmd'  => 'ai:review-graph:impact',
            'args' => ['<FQCN>', '--json'],
            'why'  => 'blast radius of changing a class in ' . $moduleName,
        ];

        $out[] = [
            'cmd'  => 'make:page',
            'args' => ['--module=' . $moduleName, '--name=<Name>', '--path=/<p>', '--method=GET', '--dry-run', '--json'],
            'why'  => 'scaffold a new page in this module (dry-run first)',
        ];

        return $out;
    }

    private function moduleRegistry(): ModuleRegistry
    {
        if ($this->moduleRegistry === null) {
            $this->moduleRegistry = new ModuleRegistry();
        }
        return $this->moduleRegistry;
    }

    /**
     * Resolve a module by registry name, alias, namespace tail, or any
     * case-insensitive variant. Studlied input ("SemitexaDemo") and slug input
     * ("semitexa-demo") both work.
     *
     * @return array<string, mixed>|null
     */
    private function resolveModule(string $requested): ?array
    {
        $modules = $this->moduleRegistry()->getModules();
        $needleLower = strtolower($requested);
        $needleSlug = $this->slugify($requested);

        foreach ($modules as $module) {
            if ($module['name'] === $requested) {
                return $module;
            }
            foreach (($module['aliases'] ?? []) as $alias) {
                if ($alias === $requested) {
                    return $module;
                }
            }
        }

        foreach ($modules as $module) {
            $candidates = array_merge(
                [(string) $module['name']],
                array_map('strval', (array) ($module['aliases'] ?? [])),
            );
            $namespace = (string) ($module['namespace'] ?? '');
            if ($namespace !== '') {
                $tail = substr($namespace, (int) strrpos($namespace, '\\') + 1);
                if ($tail !== '') {
                    $candidates[] = $tail;
                }
            }
            foreach ($candidates as $candidate) {
                if (strtolower($candidate) === $needleLower) {
                    return $module;
                }
                if ($this->slugify($candidate) === $needleSlug) {
                    return $module;
                }
            }
        }

        return null;
    }

    /**
     * Strip non-alphanumeric characters and lowercase. Lets "semitexa-demo",
     * "SemitexaDemo", "semitexa_demo", and "Semitexa Demo" all reduce to the
     * same key, so user input shape doesn't matter.
     */
    private function slugify(string $input): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]/', '', $input));
    }

    /**
     * @return list<string>
     */
    private function knownModuleNames(): array
    {
        $names = [];
        foreach ($this->moduleRegistry()->getModules() as $module) {
            $names[] = (string) $module['name'];
        }
        sort($names);
        return $names;
    }

    /**
     * @param array<string, mixed> $module
     * @return list<string>
     */
    private function resolveSourceRoots(array $module): array
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
     * Walk PHP files under each PSR-4 root and bucket them by the closest
     * directory-name convention. Bucket keys mirror the original module
     * description shape so JSON consumers stay stable.
     *
     * Closest-segment-wins handles nested layouts uniformly:
     *   Application/Handler/PayloadHandler/Foo/Bar.php → handlers
     *   Application/Payload/Event/Foo.php              → events
     *   Application/Payload/Request/Foo.php            → payloads
     *   Domain/Service/Foo.php                         → services
     *   Application/Console/Command/Foo.php            → (excluded — CLI)
     *
     * @param list<string> $sourceRoots
     * @return array{
     *   payloads: list<string>, handlers: list<string>, resources: list<string>,
     *   services: list<string>, contracts: list<string>, events: list<string>,
     *   models: list<string>, listeners: list<string>, commands: list<string>
     * }
     */
    private function scanByCategory(array $sourceRoots): array
    {
        $buckets = [
            'payloads' => [], 'handlers' => [], 'resources' => [],
            'services' => [], 'contracts' => [], 'events' => [],
            'models' => [], 'listeners' => [], 'commands' => [],
        ];

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

                $relative = ltrim(substr($file->getPathname(), strlen($realRoot)), '/\\');
                $segments = explode('/', str_replace('\\', '/', $relative));
                array_pop($segments);
                if ($segments === []) {
                    continue;
                }

                $bucket = $this->classifySegments($segments);
                if ($bucket === null) {
                    continue;
                }

                $buckets[$bucket][] = $file->getBasename('.php');
            }
        }

        foreach ($buckets as $key => $files) {
            sort($files);
            $buckets[$key] = array_values(array_unique($files));
        }

        return $buckets;
    }

    /**
     * Pick the bucket whose directory name appears closest to the file. Returns
     * null when no recognized convention name appears in the path.
     *
     * @param list<string> $segments path segments from root → leaf, file name removed
     */
    private function classifySegments(array $segments): ?string
    {
        $hasConsoleAncestor = in_array('Console', $segments, true);

        foreach (array_reverse($segments) as $segment) {
            switch ($segment) {
                case 'PayloadHandler':
                case 'SlotHandler':
                    return 'handlers';
                case 'DomainListener':
                case 'Listener':
                    return 'listeners';
                case 'Payload':
                    return 'payloads';
                case 'Resource':
                    return 'resources';
                case 'Service':
                    return 'services';
                case 'Contract':
                    return 'contracts';
                case 'Event':
                    return 'events';
                case 'Model':
                    return 'models';
                case 'Command':
                    return $hasConsoleAncestor ? null : 'commands';
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function scanFiles(string $dir, string $extension): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
            \RecursiveIteratorIterator::CATCH_GET_CHILD,
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getBasename('.' . $extension);
            }
        }

        sort($files);
        return $files;
    }
}
