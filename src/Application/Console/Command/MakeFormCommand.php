<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Generation\Builder\FormPlanBuilder;
use Semitexa\Dev\Application\Service\Generation\Data\GenerationResult;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationExitCode;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationOutcomeRenderer;
use Semitexa\Dev\Application\Service\Generation\Support\GenerationPreflight;
use Semitexa\Dev\Application\Service\Generation\Support\JsonResultFormatter;
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

/** make:form — a platform.form without CRUD: an action that declares its fields, a partial, a test. */
#[AsCommand(name: 'make:form', description: 'Scaffold a form: a submit action that declares its fields, the partial that renders them, and a test')]
final class MakeFormCommand extends BaseCommand
{
    /** {@see MakeServiceCommand::REQUIRED_OPTIONS} */
    public const REQUIRED_OPTIONS = ['module', 'name', 'fields'];

    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Form name (e.g. ContactUs)')
            ->addOption('fields', null, InputOption::VALUE_REQUIRED, 'Fields as name:type, "!" for required: "name:text!,email:email!,message:textarea"')
            ->addOption('submit-text', null, InputOption::VALUE_REQUIRED, 'The submit button (default: Send)')
            ->addOption('no-test', null, InputOption::VALUE_NONE, 'Skip the test')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rejected = GenerationPreflight::check($input, $output, 'make:form', self::REQUIRED_OPTIONS, $this->getProjectRoot());
        if ($rejected !== null) {
            return $rejected;
        }
        try {
            $fields = FormPlanBuilder::parseFields((string) $input->getOption('fields'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return self::INVALID;
        }

        $inflector = new NameInflector();
        $module = $inflector->toStudly((string) $input->getOption('module'));
        $kebab = $inflector->toKebab($inflector->toStudly((string) $input->getOption('name')));
        $plan = (new FormPlanBuilder($inflector, new TemplateResolver(), new TemplateRenderer()))->build([
            'module' => $module,
            'name' => (string) $input->getOption('name'),
            'fields' => $fields,
            'submitText' => $input->getOption('submit-text'),
            'noTest' => (bool) $input->getOption('no-test'),
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'fields', 'submit-text']);
        $steps = [
            "Put the form on a page: {% include '@project-layouts-{$module}/partials/{$kebab}-form.html.twig' %}",
            'Do the work in handle(), then bin/semitexa server:restart',
            'bin/semitexa ai:verify --dirty',
        ];

        if ($plan->dryRun) {
            $planned = new GenerationResult('make:form', 'dry_run', array_map(static fn ($f): string => $f->path, $plan->files), next_steps: ['Re-run with --write to create files', ...$steps], replay_args: $replayArgs);
            if ($input->getOption('json')) {
                $output->writeln((new JsonResultFormatter())->format($planned));

                return self::SUCCESS;
            }
            $io->title('Dry Run — Planned Files');
            foreach ($plan->files as $file) {
                $io->section($file->path);
                $output->writeln($file->content);
            }

            return self::SUCCESS;
        }

        $result = (new SafeFileWriter($this->getProjectRoot(), 'make:form'))->write($plan->files, (bool) $input->getOption('force'));
        $result = (new PostWriteLinter($this->getApplication()))->lintAfterWrite($result)->withReplayArgs($replayArgs)->withNextSteps($steps);
        if ($input->getOption('json')) {
            $output->writeln((new JsonResultFormatter())->format($result));

            return GenerationExitCode::forResult($result);
        }
        if ($result->created) {
            $io->success('Created: ' . implode(', ', $result->created));
            $io->listing($result->next_steps);
        }
        GenerationOutcomeRenderer::renderProblems($io, $result);

        return GenerationExitCode::forResult($result);
    }
}
