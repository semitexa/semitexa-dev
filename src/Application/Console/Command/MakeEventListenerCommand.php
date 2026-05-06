<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateDetector;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateGate;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateQuery;
use Semitexa\Dev\Application\Service\Ai\Similarity\SimilarityIndexBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\EventListenerPlanBuilder;
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

#[AsCommand(name: 'make:event-listener', description: 'Scaffold a new event listener with #[AsEventListener]')]
final class MakeEventListenerCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Listener class name')
            ->addOption('event', null, InputOption::VALUE_REQUIRED, 'Event class name to listen for')
            ->addOption('execution', null, InputOption::VALUE_REQUIRED, 'Execution mode: Sync, Async, or Queued')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned files without writing (explicit)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Actually create files (dry-run is the default)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing files')
            ->addOption('override-duplicate', null, InputOption::VALUE_NONE, 'Bypass duplicate/similarity refusal')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON')
            ->addOption('llm-hints', null, InputOption::VALUE_NONE, 'Output LLM hints envelope');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach (['module', 'name', 'event', 'execution'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $execution = ucfirst(strtolower($input->getOption('execution')));
        if (!in_array($execution, ['Sync', 'Async', 'Queued'], true)) {
            $io->error('--execution must be one of: Sync, Async, Queued');
            return self::FAILURE;
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new EventListenerPlanBuilder($inflector, $resolver, $renderer);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'event' => $input->getOption('event'),
            'execution' => $execution,
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        $module = $inflector->toStudly($input->getOption('module'));
        $listenerClassName = $inflector->toStudly($input->getOption('name'));
        $listenerFqcn = "Semitexa\\Modules\\{$module}\\Application\\Handler\\DomainListener\\{$listenerClassName}";
        $eventInput = trim((string) $input->getOption('event'));
        $eventFqcn = str_contains($eventInput, '\\')
            ? ltrim($eventInput, '\\')
            : "Semitexa\\Modules\\{$module}\\Domain\\Event\\" . $inflector->toStudly($eventInput);
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'event', 'execution']);
        $duplicateGate = new DuplicateGate();
        $detector = new DuplicateDetector((new SimilarityIndexBuilder($this->getProjectRoot()))->build());
        $gateExit = $duplicateGate->run(
            new DuplicateQuery(
                kind: 'listener',
                module: $module,
                className: $listenerClassName,
                fqcn: $listenerFqcn,
                relativePath: $plan->files[0]->path,
                extras: ['event_fqcn' => $eventFqcn],
            ),
            $detector,
            $io,
            $output,
            (bool) $input->getOption('override-duplicate'),
            (bool) $input->getOption('json'),
            (bool) $input->getOption('llm-hints'),
        );
        if ($gateExit !== null) {
            return $gateExit;
        }

        $plannedResult = new \Semitexa\Dev\Application\Service\Generation\Data\GenerationResult(
            command: 'make:event-listener',
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
                $output->writeln($formatter->format('event_listener_scaffold', $plannedResult, [
                    'fill_targets' => [
                        "src/modules/{$module}/src/Application/Handler/DomainListener/{$name}.php" => [
                            'Implement event handling logic in handle() method',
                            'Add #[InjectAsReadonly] properties for dependencies if needed',
                        ],
                    ],
                    'facts' => [
                        '#[AsEventListener] implies #[ExecutionScoped] — a fresh clone per execution',
                        'Sync runs in-request, Async runs after response (Swoole defer), Queued goes to queue worker',
                        'The event class must exist in Domain/Event/ of the same or another module',
                    ],
                    'constraints' => [
                        'handle() must accept exactly one parameter: the event class',
                        'handle() must return void',
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

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:event-listener');
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
            $output->writeln($formatter->format('event_listener_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/src/Application/Handler/DomainListener/{$name}.php" => [
                        'Implement event handling logic in handle() method',
                        'Add #[InjectAsReadonly] properties for dependencies if needed',
                    ],
                ],
                'facts' => [
                    '#[AsEventListener] implies #[ExecutionScoped] — a fresh clone per execution',
                    'Sync runs in-request, Async runs after response (Swoole defer), Queued goes to queue worker',
                    'The event class must exist in Domain/Event/ of the same or another module',
                ],
                'constraints' => [
                    'handle() must accept exactly one parameter: the event class',
                    'handle() must return void',
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
