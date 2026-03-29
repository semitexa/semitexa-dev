<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Generation\Builder\ModulePlanBuilder;
use Semitexa\Dev\Generation\Support\JsonResultFormatter;
use Semitexa\Dev\Generation\Support\LlmHintsFormatter;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Writer\SafeFileWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:module', description: 'Scaffold a new module with the standard directory structure')]
final class MakeModuleCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name (e.g., Catalog)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned directories without creating')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$input->getOption('name')) {
            $io->error('Missing required option: --name');
            return self::FAILURE;
        }

        $inflector = new NameInflector();
        $builder = new ModulePlanBuilder($inflector);

        $plan = $builder->build([
            'name' => $input->getOption('name'),
            'dryRun' => (bool) $input->getOption('dry-run'),
        ]);

        if ($plan->dryRun) {
            if ($input->getOption('json') || $input->getOption('llm-hints')) {
                $io->error('--dry-run cannot be combined with --json or --llm-hints.');
                return self::FAILURE;
            }

            $io->title('Dry Run — Planned Directories');
            foreach ($plan->files as $file) {
                $io->text(dirname($file->path));
            }
            return self::SUCCESS;
        }

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:module');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        $module = $inflector->toStudly($input->getOption('name'));
        if ($input->getOption('llm-hints')) {
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('module_scaffold', $result, [
                'facts' => [
                    "Module namespace: Semitexa\\Modules\\{$module}",
                    'All directories follow the standard convention and are auto-discovered',
                    'No need to register the module or add PSR-4 entries to composer.json',
                ],
                'suggested_next_prompt' => "Use make:page, make:service, or make:contract to add code to the {$module} module",
            ]));
            return self::SUCCESS;
        }

        if ($result->created) {
            $io->success("Module {$module} created with standard directory structure.");
            $io->text('Next: use make:page, make:service, or make:contract to add code.');
        }

        return self::SUCCESS;
    }
}
