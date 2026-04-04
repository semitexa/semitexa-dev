<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Generation\Builder\PagePlanBuilder;
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

#[AsCommand(name: 'make:page', description: 'Scaffold a complete page: Payload + Handler + Resource + template')]
final class MakePageCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Page name')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Route path')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method')
            ->addOption('layout', null, InputOption::VALUE_REQUIRED, 'Layout template name')
            ->addOption('public', null, InputOption::VALUE_NONE, 'Add #[PublicEndpoint]')
            ->addOption('with-assets', null, InputOption::VALUE_NONE, 'Generate CSS/JS/assets.json stubs')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name', 'path', 'method'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new PagePlanBuilder($inflector, $resolver, $renderer);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'path' => $input->getOption('path'),
            'method' => $input->getOption('method'),
            'layout' => $input->getOption('layout'),
            'public' => (bool) $input->getOption('public'),
            'withAssets' => (bool) $input->getOption('with-assets'),
            'dryRun' => (bool) $input->getOption('dry-run'),
        ]);

        if ($plan->dryRun) {
            if ($input->getOption('json')) {
                $output->writeln(json_encode($plan->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                return self::SUCCESS;
            }

            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->section($file->path);
                $output->writeln($file->content);
            }
            return self::SUCCESS;
        }

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:page');
        $result = $writer->write($plan->files, (bool) $input->getOption('force'));

        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));
            return self::SUCCESS;
        }

        if ($input->getOption('llm-hints')) {
            $module = $inflector->toStudly($input->getOption('module'));
            $name = $inflector->toStudly($input->getOption('name'));
            $kebab = $inflector->toKebab($name);
            $formatter = new LlmHintsFormatter();
            $output->writeln($formatter->format('page_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/Application/Payload/Request/{$inflector->toPayloadClass($name)}.php" => [
                        'Add properties for request parameters',
                        'Implement validation rules in validate()',
                    ],
                    "src/modules/{$module}/Application/Handler/PayloadHandler/{$inflector->toHandlerClass($name)}.php" => [
                        'Implement business logic in handle()',
                        'Populate resource via fluent setters',
                    ],
                    "src/modules/{$module}/Application/Resource/Response/{$inflector->toResponseClass($name)}.php" => [
                        'Add fluent with*() setter methods',
                    ],
                    "src/modules/{$module}/Application/View/templates/pages/{$kebab}.html.twig" => [
                        'Build the page HTML template',
                    ],
                ],
                'facts' => [
                    'All three classes are auto-discovered via PHP attributes',
                    'The handler receives a hydrated payload and empty resource',
                    'Template variables are set via Resource::with() method',
                ],
                'constraints' => [
                    'Handler must be final class',
                    'Resource must extend HtmlResponse and implement ResourceInterface',
                    'Payload must implement ValidatablePayload',
                ],
                'suggested_next_prompt' => "Open the handler at src/modules/{$module}/Application/Handler/PayloadHandler/{$inflector->toHandlerClass($name)}.php and implement the business logic.",
            ]));
            return self::SUCCESS;
        }

        if ($result->created) {
            $io->success('Created ' . count($result->created) . ' files:');
            foreach ($result->created as $path) {
                $io->text("  - {$path}");
            }
        }
        if ($result->conflicts) {
            $io->warning('Conflicts: ' . implode(', ', $result->conflicts));
        }

        return self::SUCCESS;
    }
}
