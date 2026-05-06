<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Generation\Builder\CommandPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\JsonResultFormatter;
use Semitexa\Dev\Application\Service\Generation\Support\LlmHintsFormatter;
use Semitexa\Dev\Application\Service\Generation\Support\NameInflector;
use Semitexa\Dev\Application\Service\Generation\Support\ReplayArgBuilder;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\TemplateResolver;
use Semitexa\Dev\Application\Service\Generation\Verifier\PostWriteLinter;
use Semitexa\Dev\Application\Service\Generation\Writer\SafeFileWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:command', description: 'Scaffold a new CLI command with #[AsCommand]')]
final class MakeCommandCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Command class name (e.g., ImportUsers)')
            ->addOption('command-name', null, InputOption::VALUE_REQUIRED, 'CLI command name (e.g., users:import)')
            ->addOption('description', null, InputOption::VALUE_REQUIRED, 'Command description')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name', 'command-name', 'description'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new CommandPlanBuilder($inflector, $resolver, $renderer);
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'command-name', 'description']);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'commandName' => $input->getOption('command-name'),
            'description' => $input->getOption('description'),
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        $plannedResult = new \Semitexa\Dev\Application\Service\Generation\Data\GenerationResult(
            command: 'make:command',
            status: 'dry_run',
            created: array_map(static fn($file): string => $file->path, $plan->files),
            next_steps: ['Re-run with --write to create files'],
            replay_args: $replayArgs,
        );

        if ($plan->dryRun) {
            if ($input->getOption('json')) {
                $output->writeln((new JsonResultFormatter())->format($plannedResult));
                return self::SUCCESS;
            }

            if ($input->getOption('llm-hints')) {
                $module = $inflector->toStudly($input->getOption('module'));
                $className = $inflector->toCommandClass($input->getOption('name'));
                $formatter = new LlmHintsFormatter();
                $output->writeln($formatter->format('command_scaffold', $plannedResult, [
                    'fill_targets' => [
                        "src/modules/{$module}/src/Application/Console/Command/{$className}.php" => [
                            'Add arguments and options in configure()',
                            'Implement command logic in execute()',
                        ],
                    ],
                    'facts' => [
                        '#[AsCommand] auto-discovers the command — no manual registration needed',
                        'BaseCommand provides getProjectRoot() and rebuildAutoload() helpers',
                    ],
                    'constraints' => [
                        'execute() must return Command::SUCCESS or Command::FAILURE',
                    ],
                ]));
                return self::SUCCESS;
            }

            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->section($file->path);
                $output->writeln($file->content);
            }
            return self::SUCCESS;
        }

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:command');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result);
        $result = $result->withReplayArgs($replayArgs);

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $module = $inflector->toStudly($input->getOption('module'));
            $className = $inflector->toCommandClass($input->getOption('name'));
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('command_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/src/Application/Console/Command/{$className}.php" => [
                        'Add arguments and options in configure()',
                        'Implement command logic in execute()',
                    ],
                ],
                'facts' => [
                    '#[AsCommand] auto-discovers the command — no manual registration needed',
                    'BaseCommand provides getProjectRoot() and rebuildAutoload() helpers',
                ],
                'constraints' => [
                    'execute() must return Command::SUCCESS or Command::FAILURE',
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
