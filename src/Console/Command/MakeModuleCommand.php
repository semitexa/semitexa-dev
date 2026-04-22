<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Generation\Builder\ModulePlanBuilder;
use Semitexa\Dev\Generation\Support\JsonResultFormatter;
use Semitexa\Dev\Generation\Support\LlmHintsFormatter;
use Semitexa\Dev\Generation\Support\NameInflector;
use Semitexa\Dev\Generation\Support\ReplayArgBuilder;
use Semitexa\Dev\Generation\Verifier\PostWriteLinter;
use Semitexa\Dev\Generation\Writer\SafeFileWriter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'make:module', description: 'Scaffold a new module with the standard directory structure')]
final class MakeModuleCommand extends BaseCommand
{
    private const TARGET_CUSTOM = ModulePlanBuilder::TARGET_CUSTOM;
    private const TARGET_PACKAGE = ModulePlanBuilder::TARGET_PACKAGE;

    protected function configure(): void
    {
        $this
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Module name (e.g., Catalog)')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Module target: custom (src/modules) or package (packages/semitexa-...)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned directories without creating (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create directories (dry-run is the default)')
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
        $target = $this->resolveTarget($input, $io);
        if ($target === null) {
            return self::FAILURE;
        }
        $replayArgs = $this->buildReplayArgs($input, $target);
        $module = $inflector->toStudly($input->getOption('name'));

        $plan = $builder->build([
            'name' => $input->getOption('name'),
            'target' => $target,
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        $plannedResult = new \Semitexa\Dev\Generation\Data\GenerationResult(
            command: 'make:module',
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
                $formatter = new LlmHintsFormatter();
                $output->writeln($formatter->format('module_scaffold', $plannedResult, [
                    'facts' => $this->buildFacts($module, $target),
                    'suggested_next_prompt' => "Use make:page, make:service, or make:contract to add code to the {$module} module",
                ]));
                return self::SUCCESS;
            }

            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->text($file->path);
            }
            return self::SUCCESS;
        }

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:module');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result);
        $result = $result->withReplayArgs($replayArgs);

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('module_scaffold', $result, [
                'facts' => $this->buildFacts($module, $target),
                'suggested_next_prompt' => "Use make:page, make:service, or make:contract to add code to the {$module} module",
            ]));
            return self::SUCCESS;
        }

        if ($result->created) {
            $io->success(sprintf(
                'Module %s created as a %s module in %s.',
                $module,
                $target,
                $this->targetRootLabel($module, $target),
            ));
            $io->text('Next: use make:page, make:service, or make:contract to add code.');
        }

        return self::SUCCESS;
    }

    private function resolveTarget(InputInterface $input, SymfonyStyle $io): ?string
    {
        $target = $input->getOption('target');
        if (is_string($target) && $target !== '') {
            if (in_array($target, [self::TARGET_CUSTOM, self::TARGET_PACKAGE], true)) {
                return $target;
            }

            $io->error('Invalid --target. Allowed values: custom, package.');
            return null;
        }

        if (!$input->isInteractive() || $input->getOption('json') || $input->getOption('llm-hints')) {
            return self::TARGET_CUSTOM;
        }

        $io->section('Choose module target');
        $io->text('`custom`: create a project-specific module in `src/modules/{Module}`. Choose this when the code belongs only to the current app and does not need package metadata.');
        $io->text('`package`: create a reusable module package in `packages/semitexa-{module}` with its own `composer.json`. Choose this when the module should be versioned, released, or shared across projects.');
        $io->text('Both options follow the same Semitexa module structure. The real difference is ownership and where the module lives.');

        return $io->choice(
            'Which target should `make:module` scaffold?',
            [self::TARGET_CUSTOM, self::TARGET_PACKAGE],
            self::TARGET_CUSTOM,
        );
    }

    /**
     * @return list<string>
     */
    private function buildReplayArgs(InputInterface $input, string $target): array
    {
        $args = ReplayArgBuilder::fromInput($input, ['name']);
        $args[] = '--target=' . $target;

        return $args;
    }

    /**
     * @return list<string>
     */
    private function buildFacts(string $module, string $target): array
    {
        if ($target === self::TARGET_PACKAGE) {
            $slug = $this->moduleSlug($module);
            return [
                sprintf('Composer package: semitexa/%s', $slug),
                sprintf('Source root: packages/semitexa-%s/src', $slug),
                sprintf('Module namespace: Semitexa\\%s', $module),
                'Use this when the module should be reusable, versioned, or shipped independently.',
            ];
        }

        return [
            sprintf('Module namespace: Semitexa\\Modules\\%s', $module),
            sprintf('Source root: src/modules/%s', $module),
            'All directories follow the standard convention and are auto-discovered.',
            'Use this when the module is app-specific and does not need its own package lifecycle.',
        ];
    }

    private function targetRootLabel(string $module, string $target): string
    {
        if ($target === self::TARGET_PACKAGE) {
            return sprintf('packages/semitexa-%s', $this->moduleSlug($module));
        }

        return sprintf('src/modules/%s', $module);
    }

    private function moduleSlug(string $module): string
    {
        return (new NameInflector())->toKebab($module);
    }
}
