<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateDetector;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateGate;
use Semitexa\Dev\Application\Service\Ai\Similarity\DuplicateQuery;
use Semitexa\Dev\Application\Service\Ai\Similarity\SimilarityIndexBuilder;
use Semitexa\Dev\Application\Service\Generation\Builder\PayloadPlanBuilder;
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

#[AsCommand(name: 'make:payload', description: 'Scaffold a new Payload DTO class')]
final class MakePayloadCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->addOption('module', null, InputOption::VALUE_REQUIRED, 'Target module name')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'Payload name without suffix')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Route path')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method')
            ->addOption('response', null, InputOption::VALUE_REQUIRED, 'Response class name without suffix')
            ->addOption('access', null, InputOption::VALUE_REQUIRED, 'Payload access type: public | protected | service', 'protected')
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

        foreach (['module', 'name', 'path', 'method', 'response'] as $required) {
            if (!$input->getOption($required)) {
                $io->error("Missing required option: --{$required}");
                return self::FAILURE;
            }
        }

        $inflector = new NameInflector();
        $resolver = new TemplateResolver();
        $renderer = new TemplateRenderer();
        $builder = new PayloadPlanBuilder($inflector, $resolver, $renderer);

        $plan = $builder->build([
            'module' => $input->getOption('module'),
            'name' => $input->getOption('name'),
            'path' => $input->getOption('path'),
            'method' => $input->getOption('method'),
            'response' => $input->getOption('response'),
            'access' => (string) $input->getOption('access'),
            'dryRun' => $input->getOption('dry-run') || !$input->getOption('write'),
        ]);

        $module = $inflector->toStudly($input->getOption('module'));
        $payloadClassName = $inflector->toPayloadClass($input->getOption('name'));
        $payloadFqcn = "Semitexa\\Modules\\{$module}\\Application\\Payload\\Request\\{$payloadClassName}";
        $replayArgs = ReplayArgBuilder::fromInput($input, ['module', 'name', 'path', 'method', 'response', 'access'], []);
        $duplicateGate = new DuplicateGate();
        $detector = new DuplicateDetector((new SimilarityIndexBuilder($this->getProjectRoot()))->build());
        $gateExit = $duplicateGate->run(
            new DuplicateQuery(
                kind: 'payload',
                module: $module,
                className: $payloadClassName,
                fqcn: $payloadFqcn,
                relativePath: $plan->files[0]->path,
                extras: [
                    'route_path'   => (string) $input->getOption('path'),
                    'route_method' => strtoupper((string) $input->getOption('method')),
                ],
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
            command: 'make:payload',
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
                $output->writeln($formatter->format('payload_scaffold', $plannedResult, [
                    'fill_targets' => [
                        "src/modules/{$module}/Application/Payload/Request/{$inflector->toPayloadClass($name)}.php" => [
                            'Add properties for request parameters',
                            'Throw Semitexa\\Core\\Exception\\ValidationException from setters to reject invalid input',
                        ],
                    ],
                    'facts' => [
                        'Payload classes are auto-discovered via #[AsPublicPayload] / #[AsProtectedPayload] / #[AsServicePayload] (one is required)',
                        'Payload setters throw Semitexa\\Core\\Exception\\ValidationException to reject invalid input at hydration time',
                    ],
                    'constraints' => [
                        'Do not add constructor — properties are hydrated via setters or public access',
                    ],
                    'suggested_next_prompt' => "Now create the handler: bin/semitexa make:handler --module={$module} --name={$name} --payload={$name} --resource={$name} --write",
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

        $writer = new SafeFileWriter($this->getProjectRoot(), 'make:payload');
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
            $output->writeln($formatter->format('payload_scaffold', $result, [
                'fill_targets' => [
                    "src/modules/{$module}/Application/Payload/Request/{$inflector->toPayloadClass($name)}.php" => [
                        'Add properties for request parameters',
                        'Throw Semitexa\\Core\\Exception\\ValidationException from setters to reject invalid input',
                    ],
                ],
                'facts' => [
                    'Payload classes are auto-discovered via #[AsPublicPayload] / #[AsProtectedPayload] / #[AsServicePayload] (one is required)',
                    'Payload setters throw Semitexa\\Core\\Exception\\ValidationException to reject invalid input at hydration time',
                ],
                'constraints' => [
                    'Do not add constructor — properties are hydrated via setters or public access',
                ],
                    'suggested_next_prompt' => "Now create the handler: bin/semitexa make:handler --module={$module} --name={$name} --payload={$name} --resource={$name} --write",
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
