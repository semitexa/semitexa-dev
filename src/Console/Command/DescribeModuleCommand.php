<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Generation\Support\NameInflector;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'describe:module', description: 'Show module structure: routes, handlers, services, contracts, listeners')]
final class DescribeModuleCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('name')) {
            $io->error('Missing required option: --name');
            return self::FAILURE;
        }

        $inflector = new NameInflector();
        $module = $inflector->toStudly($input->getOption('name'));
        $root = $this->getProjectRoot();
        $modulePath = $root . '/src/modules/' . $module;

        if (!is_dir($modulePath)) {
            $io->error("Module not found: {$modulePath}");
            return self::FAILURE;
        }

        $description = [
            'module' => $module,
            'namespace' => "Semitexa\\Modules\\{$module}",
            'path' => "src/modules/{$module}",
            'payloads' => $this->scanPhpFiles($modulePath . '/Application/Payload/Request'),
            'handlers' => $this->scanPhpFiles($modulePath . '/Application/Handler/PayloadHandler'),
            'resources' => $this->scanPhpFiles($modulePath . '/Application/Resource/Response'),
            'services' => $this->scanPhpFiles($modulePath . '/Domain/Service'),
            'contracts' => $this->scanPhpFiles($modulePath . '/Domain/Contract'),
            'events' => $this->scanPhpFiles($modulePath . '/Domain/Event'),
            'models' => $this->scanPhpFiles($modulePath . '/Domain/Model'),
            'listeners' => $this->scanPhpFiles($modulePath . '/Application/Handler/DomainListener'),
            'commands' => $this->scanPhpFiles($modulePath . '/Application/Command'),
            'templates' => $this->scanFiles($modulePath . '/Application/View/templates', 'twig'),
        ];

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => 'semitexa-dev.module-description/v1',
                'generated_at' => date('c'),
                'module' => $description,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $io->title("Module: {$module}");
        $io->text("Namespace: Semitexa\\Modules\\{$module}");
        $io->text("Path: src/modules/{$module}");
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

    /** @return list<string> */
    private function scanPhpFiles(string $dir): array
    {
        return $this->scanFiles($dir, 'php');
    }

    /** @return list<string> */
    private function scanFiles(string $dir, string $extension): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getBasename('.' . $extension);
            }
        }

        sort($files);
        return $files;
    }
}
