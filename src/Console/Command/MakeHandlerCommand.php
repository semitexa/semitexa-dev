<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Generation\Builder\HandlerPlanBuilder;
use Semitexa\Dev\Generation\Support\JsonResultFormatter;
use Semitexa\Dev\Generation\Support\LlmHintsFormatter;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;
use Semitexa\Dev\Generation\Writer\SafeFileWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:handler', description: 'Scaffold a new Handler class')]
final class MakeHandlerCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Handler name without suffix')
            ->addOption('payload', null, InputOption::VALUE_REQUIRED, 'Payload class name without suffix')
            ->addOption('resource', null, InputOption::VALUE_REQUIRED, 'Resource class name without suffix')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name', 'payload', 'resource'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new HandlerPlanBuilder($inflector, $resolver, $renderer);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'payload' => $input->getOption('payload'),
            'resource' => $input->getOption('resource'),
            'dryRun' => (bool) $input->getOption('dry-run'),
        ]);

        if ($plan->dryRun) {
            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->section($file->path);
                $output->writeln($file->content);
            }
            return self::SUCCESS;
        }

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:handler');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $module = $inflector->toStudly($input->getOption('module'));
            $name = $inflector->toStudly($input->getOption('name'));
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('handler_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/Application/Handler/PayloadHandler/{$inflector->toHandlerClass($name)}.php" => [
                        'Implement business logic in handle() method',
                        'Populate the resource with data via fluent setters',
                    ],
                ],
                'facts' => [
                    'Handler is auto-discovered via #[AsPayloadHandler] attribute',
                    'The handle() method receives a hydrated payload and empty resource',
                ],
                'constraints' => [
                    'Handler must be final and implement TypedHandlerInterface',
                    'Return type must match the resource parameter type',
                ],
            ]));
            return self::SUCCESS;
        }

        if ($result->created) {
            $io->success('Created: ' . implode(', ', $result->created));
        }
        if ($result->conflicts) {
            $io->warning('Conflicts: ' . implode(', ', $result->conflicts));
        }

        return self::SUCCESS;
    }
}
