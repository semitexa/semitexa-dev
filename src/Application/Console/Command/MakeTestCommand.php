<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Generation\Builder\TestPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
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

#[AsCommand(name: 'make:test', description: 'Scaffold PHPUnit tests for a payload and/or handler')]
final class MakeTestCommand extends BaseCommand
{
    private const TARGETS = [
        TestPlanBuilder::TARGET_PAYLOAD,
        TestPlanBuilder::TARGET_HANDLER,
        TestPlanBuilder::TARGET_BOTH,
    ];

    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Subject name without suffix (e.g., Pricing)')
            ->addOption('for', null, InputOption::VALUE_REQUIRED, 'What to scaffold: payload | handler | both', TestPlanBuilder::TARGET_BOTH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $target = (string) $input->getOption('for');
        if (!in_array($target, self::TARGETS, true)) {
            $io->error("Invalid --for value '{$target}'. Expected one of: " . implode(', ', self::TARGETS));
            return self::FAILURE;
        }

        $inflector = new NameInflector();
        $builder = new TestPlanBuilder($inflector, new TemplateResolver(), new TemplateRenderer());
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'for']);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'target' => $target,
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        if ($plan->dryRun) {
            $plannedResult = new GenerationResult(
                command: 'make:test',
                status: 'dry_run',
                created: array_map(static fn($file): string => $file->path, $plan->files),
                next_steps: ['Re-run with --write to create files', 'Run `bin/semitexa ai:verify` to execute the new tests'],
                replay_args: $replayArgs,
            );

            if ($input->getOption('json')) {
                $output->writeln((new JsonResultFormatter())->format($plannedResult));
                return self::SUCCESS;
            }

            if ($input->getOption('llm-hints')) {
                $output->writeln($this->llmHints($plannedResult));
                return self::SUCCESS;
            }

            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->section($file->path);
                $output->writeln($file->content);
            }
            return self::SUCCESS;
        }

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:test');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result);
        $result = $result->withReplayArgs($replayArgs);

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $output->writeln($this->llmHints($result));
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

    private function llmHints(GenerationResult $result): string
    {
        return (new LlmHintsFormatter())->format('test_scaffold', $result, [
            'facts' => [
                'The payload contract test runs strategies declared via #[TestablePayload] through semitexa-testing.',
                'The handler test is an instantiation smoke; injection happens via #[InjectAsReadonly] at runtime.',
                'Tests land under tests/Integration and tests/Unit with the App\\Tests\\Modules\\<Module>\\… namespace.',
            ],
            'constraints' => [
                'Add #[TestablePayload(strategies: [...])] to the payload, or the contract test reports SKIP.',
                'Replace the handler-test TODO with a real assertion exercising handle().',
            ],
        ]);
    }
}
