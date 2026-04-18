<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Semitexa\Dev\Ai\Classifier\TaskClassifier;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Semitexa\Dev\Ai\Trace\TraceStore;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai:task', description: 'Classify a task description into a recipe + suggested make:* invocation')]
final class AiTaskCommand extends BaseCommand
{
    public function __construct()
    {
        parent::__construct('ai:task');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('description', InputArgument::REQUIRED, 'One-line task description (quote it)')
            ->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Hint the target module name')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single-line JSON envelope (legacy compat)')
            ->addOption('ndjson', null, InputOption::VALUE_NONE, 'Emit NDJSON: one fact per line (default for agents)')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a task_result event to this ai:trace id (falls back to $SEMITEXA_AI_TRACE_ID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $description = (string) $input->getArgument('description');
        $hintModule = $input->getOption('module');

        $result = (new TaskClassifier())->classify($description, $hintModule);

        $useJson = (bool) $input->getOption('json');
        $useNdjson = (bool) $input->getOption('ndjson') || (!$useJson && !$input->isInteractive());

        if ($useNdjson) {
            $this->emitNdjson($output, $result, $description);
            $this->appendToTrace($input, $output, $result, $description);
            return self::SUCCESS;
        }

        if ($useJson) {
            $output->writeln(json_encode([
                'artifact'         => 'semitexa.ai-task/v1',
                'recipe'           => $result->recipe->id,
                'label'            => $result->recipe->label,
                'score'            => $result->score,
                'reason'           => $result->reason,
                'suggested_module' => $result->suggested_module,
                'risk_hint'        => $result->recipe->default_risk,
                'generator_chain'  => $result->recipe->generator_chain,
                'next'             => $this->buildNextHint($result),
                'alternatives'     => $result->alternatives,
            ], JSON_UNESCAPED_SLASHES));
            $this->appendToTrace($input, $output, $result, $description);
            return self::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);
        $io->title("Task: {$description}");
        $io->definitionList(
            ['Recipe' => "{$result->recipe->id} (score {$result->score})"],
            ['Label' => $result->recipe->label],
            ['Reason' => $result->reason],
            ['Risk hint' => $result->recipe->default_risk],
            ['Module' => $result->suggested_module ?? '— (not inferred)'],
        );
        if ($result->recipe->generator_chain !== []) {
            $io->section('Generator chain');
            foreach ($result->recipe->generator_chain as $step) {
                $io->writeln("  • {$step}");
            }
        }
        $io->section('Next');
        $io->writeln('  ' . $this->buildNextHint($result));
        if ($result->alternatives !== []) {
            $io->section('Alternatives');
            foreach ($result->alternatives as $alt) {
                $io->writeln("  • {$alt['recipe_id']} (score {$alt['score']})");
            }
        }
        $this->appendToTrace($input, $output, $result, $description);
        return self::SUCCESS;
    }

    private function appendToTrace(InputInterface $input, OutputInterface $output, $result, string $description): void
    {
        $summary = sprintf(
            'classified "%s" → %s (score %d)',
            $this->truncate($description, 60),
            $result->recipe->id,
            $result->score,
        );
        $payload = [
            'artifact'         => 'semitexa.ai-task/v1',
            'description'      => $description,
            'recipe'           => $result->recipe->id,
            'label'            => $result->recipe->label,
            'score'            => $result->score,
            'reason'           => $result->reason,
            'suggested_module' => $result->suggested_module,
            'risk_hint'        => $result->recipe->default_risk,
            'generator_chain'  => $result->recipe->generator_chain,
            'alternatives'     => $result->alternatives,
        ];
        $appender = new TraceAutoAppender(new TraceStore($this->getProjectRoot()));
        $appender->appendIfActive($input, $output, TraceEventKind::TASK_RESULT, $summary, $payload);
    }

    private function truncate(string $value, int $max): string
    {
        return strlen($value) > $max ? substr($value, 0, $max - 1) . '…' : $value;
    }

    private function emitNdjson(OutputInterface $output, $result, string $description): void
    {
        $output->writeln(json_encode([
            'kind'             => 'summary',
            'recipe'           => $result->recipe->id,
            'label'            => $result->recipe->label,
            'score'            => $result->score,
            'risk_hint'        => $result->recipe->default_risk,
            'suggested_module' => $result->suggested_module,
            'reason'           => $result->reason,
            'generator_steps'  => count($result->recipe->generator_chain),
            'alternatives'     => count($result->alternatives),
        ], JSON_UNESCAPED_SLASHES));

        foreach ($result->recipe->generator_chain as $i => $command) {
            $output->writeln(json_encode([
                'kind'   => 'step',
                'order'  => $i + 1,
                'command' => $command,
                'action' => 'run',
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($result->recipe->arg_hints as $arg => $hint) {
            $output->writeln(json_encode([
                'kind'  => 'arg',
                'name'  => $arg,
                'hint'  => $hint,
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($result->alternatives as $alt) {
            $output->writeln(json_encode([
                'kind'      => 'alternative',
                'recipe'    => $alt['recipe_id'],
                'score'     => $alt['score'],
            ], JSON_UNESCAPED_SLASHES));
        }

        $output->writeln(json_encode([
            'kind'   => 'next',
            'action' => 'invoke',
            'hint'   => $this->buildNextHint($result),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function buildNextHint($result): string
    {
        if ($result->recipe->generator_chain === []) {
            return "no scaffold available for {$result->recipe->id}; edit existing files and run ai:context {$result->recipe->id} for prior art.";
        }
        $first = $result->recipe->generator_chain[0];
        $args = ['--write'];
        if ($result->suggested_module !== null) {
            $args[] = "--module={$result->suggested_module}";
        }
        return $first . ' ' . implode(' ', $args) . ' (then run remaining steps in chain).';
    }
}
