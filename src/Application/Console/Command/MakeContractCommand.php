<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Generation\Builder\ContractPlanBuilder;
use Semitexa\Dev\Generation\Support\JsonResultFormatter;
use Semitexa\Dev\Generation\Support\LlmHintsFormatter;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\ReplayArgBuilder;
use Semitexa\Dev\Generation\Support\TemplateRenderer;
use Semitexa\Dev\Generation\Support\TemplateResolver;
use Semitexa\Dev\Generation\Verifier\PostWriteLinter;
use Semitexa\Dev\Generation\Writer\SafeFileWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:contract', description: 'Scaffold a service contract interface + implementation')]
final class MakeContractCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Contract interface name (e.g., PaymentGateway)')
            ->addOption('implementation', null, InputOption::VALUE_REQUIRED, 'Implementation class name (e.g., StripePaymentGateway)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name', 'implementation'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new ContractPlanBuilder($inflector, $resolver, $renderer);
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'implementation']);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'implementation' => $input->getOption('implementation'),
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        $plannedResult = new \Semitexa\Dev\Generation\Data\GenerationResult(
            command: 'make:contract',
            status: 'dry_run',
            created: array_map(static fn($file): string => $file->path, $plan->files),
            next_steps: ['Re-run with --write to create files'],
            replay_args: $replayArgs,
        );

        if ($plan->dryRun) {
            $module = $inflector->toStudly($input->getOption('module'));
            $name = $inflector->toStudly($input->getOption('name'));
            $interfaceClass = str_ends_with($name, 'Interface') ? $name : $name . 'Interface';
            $implName = $inflector->toStudly($input->getOption('implementation'));

            if ($input->getOption('json')) {
                $output->writeln((new JsonResultFormatter())->format($plannedResult));
                return self::SUCCESS;
            }

            if ($input->getOption('llm-hints')) {
                $formatter = new LlmHintsFormatter();
                $output->writeln($formatter->format('contract_scaffold', $plannedResult, [
                    'fill_targets' => [
                        "src/modules/{$module}/Domain/Contract/{$interfaceClass}.php" => [
                            'Define interface methods',
                        ],
                        "src/modules/{$module}/Domain/Service/{$implName}.php" => [
                            'Implement all interface methods',
                            'Add #[InjectAsReadonly] properties for dependencies',
                        ],
                    ],
                    'facts' => [
                        '#[SatisfiesServiceContract] auto-registers this implementation in the DI container',
                        'If another module provides a competing implementation, module "extends" priority determines the winner',
                        'Run bin/semitexa contracts:list to verify the active binding',
                    ],
                    'constraints' => [
                        'Implementation must actually implement the interface',
                        'Use #[InjectAsReadonly] for dependencies, never constructor injection',
                    ],
                    'suggested_next_prompt' => "Run: bin/semitexa contracts:list --json to verify the binding",
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

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:contract');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result);
        $result = $result->withReplayArgs($replayArgs);
        $hasConflicts = $result->conflicts !== [];

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return $hasConflicts ? self::FAILURE : self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $module = $inflector->toStudly($input->getOption('module'));
            $name = $inflector->toStudly($input->getOption('name'));
            $interfaceClass = str_ends_with($name, 'Interface') ? $name : $name . 'Interface';
            $implName = $inflector->toStudly($input->getOption('implementation'));
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('contract_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/Domain/Contract/{$interfaceClass}.php" => [
                        'Define interface methods',
                    ],
                    "src/modules/{$module}/Domain/Service/{$implName}.php" => [
                        'Implement all interface methods',
                        'Add #[InjectAsReadonly] properties for dependencies',
                    ],
                ],
                'facts' => [
                    '#[SatisfiesServiceContract] auto-registers this implementation in the DI container',
                    'If another module provides a competing implementation, module "extends" priority determines the winner',
                    'Run bin/semitexa contracts:list to verify the active binding',
                ],
                'constraints' => [
                    'Implementation must actually implement the interface',
                    'Use #[InjectAsReadonly] for dependencies, never constructor injection',
                ],
                'suggested_next_prompt' => "Run: bin/semitexa contracts:list --json to verify the binding",
            ]));
            return $hasConflicts ? self::FAILURE : self::SUCCESS;
        }

        if ($result->created) {
            $io->success('Created: ' . implode(', ', $result->created));
        }
        if ($result->conflicts) {
            $io->warning('Conflicts: ' . implode(', ', $result->conflicts));
        }

        return $hasConflicts ? self::FAILURE : self::SUCCESS;
    }
}
