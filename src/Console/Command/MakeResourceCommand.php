<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Generation\Builder\ResourcePlanBuilder;
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

#[AsCommand(name: 'make:resource', description: 'Scaffold a new Resource (Response) class')]
final class MakeResourceCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Resource name without suffix')
            ->addOption('handle', null, InputOption::VALUE_REQUIRED, 'Render handle name')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Custom Twig template path')
            ->addOption('with-template', null, InputOption::VALUE_NONE, 'Generate a Twig template file')
            ->addOption('with-assets', null, InputOption::VALUE_NONE, 'Generate CSS/JS/assets.json stubs')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name', 'handle'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new ResourcePlanBuilder($inflector, $resolver, $renderer);
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'handle', 'template'], ['with-template', 'with-assets']);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'handle' => $input->getOption('handle'),
            'template' => $input->getOption('template'),
            'withTemplate' => (bool) $input->getOption('with-template'),
            'withAssets' => (bool) $input->getOption('with-assets'),
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        $plannedResult = new \Semitexa\Dev\Generation\Data\GenerationResult(
            command: 'make:resource',
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
                $name = $inflector->toStudly($input->getOption('name'));
                $formatter = new LlmHintsFormatter();
                $output->writeln($formatter->format('resource_scaffold', $plannedResult, [
                    'fill_targets' => [
                        "src/modules/{$module}/Application/Resource/Response/{$inflector->toResponseClass($name)}.php" => [
                            'Add fluent with*() setter methods for template variables',
                            'Each setter should call $this->with($key, $value)',
                        ],
                    ],
                    'facts' => [
                        'Resource is auto-discovered via #[AsResource] attribute',
                        'HtmlResponse provides pageTitle(), seoTag(), with() helpers',
                        'The template path uses @project-layouts-{Module}/ prefix by convention',
                    ],
                    'constraints' => [
                        'Must extend HtmlResponse and implement ResourceInterface',
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

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:resource');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result);
        $result = $result->withReplayArgs($replayArgs);

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $module = $inflector->toStudly($input->getOption('module'));
            $name = $inflector->toStudly($input->getOption('name'));
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('resource_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/Application/Resource/Response/{$inflector->toResponseClass($name)}.php" => [
                        'Add fluent with*() setter methods for template variables',
                        'Each setter should call $this->with($key, $value)',
                    ],
                ],
                'facts' => [
                    'Resource is auto-discovered via #[AsResource] attribute',
                    'HtmlResponse provides pageTitle(), seoTag(), with() helpers',
                    'The template path uses @project-layouts-{Module}/ prefix by convention',
                ],
                'constraints' => [
                    'Must extend HtmlResponse and implement ResourceInterface',
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
