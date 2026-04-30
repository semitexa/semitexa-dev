<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Ai\Recipe\Recipe;
use Semitexa\Dev\Ai\Recipe\RecipeRegistry;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Unified recipe-driven scaffold surface.
 *
 *   semitexa make --recipe=add_authenticated_json_route \
 *                 --arg=module=billing --arg=name=get-invoice --arg=path=/billing/{id} \
 *                 --arg=method=GET --arg=response=get-invoice --arg=payload=get-invoice \
 *                 --write
 *
 * Dispatches each step in the recipe's `generator_chain` to the existing
 * `make:*` commands — the individual aliases keep working untouched. The
 * shared surface gives agents one entry point to memorise, plus a consistent
 * NDJSON/JSON report of every step's result.
 *
 * First milestone: straight passthrough, no orchestration magic. Later we
 * can add per-recipe arg transformation, cross-step data flow, and a
 * "plan only" mode that emits the full chain's dry-run before executing.
 */
#[AsCommand(name: 'make', description: 'Recipe-driven scaffold dispatcher — runs a recipe\'s generator_chain end-to-end')]
final class MakeCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TraceAutoAppender $traceAppender;

    public function __construct()
    {
        parent::__construct('make');
    }

    protected function configure(): void
    {
        $this
            ->addOption('recipe', null, InputOption::VALUE_REQUIRED, 'Recipe id (see `ai:ask capabilities` or RecipeRegistry)')
            ->addOption('arg', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Forward a key=value arg to every generator step (repeatable)')
            ->addOption('write', null, InputOption::VALUE_NONE, 'Pass --write to each generator step (default: dry-run)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Pass --force to each generator step')
            ->addOption('override-duplicate', null, InputOption::VALUE_NONE, 'Pass --override-duplicate to each generator step')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Emit a single JSON envelope with per-step results')
            ->addOption('ndjson', null, InputOption::VALUE_NONE, 'Emit one JSON line per step (default for agents)')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a scaffold_action event to this ai:trace id (falls back to $SEMITEXA_AI_TRACE_ID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $recipeId = (string) $input->getOption('recipe');
        if ($recipeId === '') {
            $io->error('Missing required option: --recipe');
            return self::FAILURE;
        }

        $recipe = RecipeRegistry::find($recipeId);
        if ($recipe === null) {
            $io->error("Unknown recipe: {$recipeId}");
            return self::FAILURE;
        }
        if ($recipe->generator_chain === []) {
            $io->error("Recipe '{$recipeId}' has no generator_chain — nothing to scaffold.");
            return self::FAILURE;
        }

        $sharedArgs = $this->parseArgs((array) $input->getOption('arg'));
        $write = (bool) $input->getOption('write');
        $force = (bool) $input->getOption('force');
        $override = (bool) $input->getOption('override-duplicate');
        $jsonMode = (bool) $input->getOption('json');
        $ndjsonMode = (bool) $input->getOption('ndjson') || (!$jsonMode && !$input->isInteractive());

        $stepResults = [];
        $overallExit = self::SUCCESS;

        foreach ($recipe->generator_chain as $i => $commandName) {
            $stepExit = $this->runStep(
                $commandName,
                $sharedArgs,
                $write,
                $force,
                $override,
                $jsonMode || $ndjsonMode,
                $stepResults,
                $i + 1,
                $recipe,
                $io,
                $ndjsonMode ? $output : null,
            );
            if ($stepExit !== self::SUCCESS) {
                $overallExit = $stepExit;
                break;
            }
        }

        $envelope = [
            'artifact'     => 'semitexa-dev.make-dispatch/v1',
            'generated_at' => date('c'),
            'recipe'       => $recipe->id,
            'status'       => $overallExit === self::SUCCESS ? 'ok' : 'failed',
            'steps'        => $stepResults,
        ];
        if ($jsonMode) {
            $output->writeln(json_encode($envelope, JSON_UNESCAPED_SLASHES));
        }

        $this->appendToTrace($input, $output, $recipe, $envelope, $stepResults);

        return $overallExit;
    }

    /**
     * @param list<array<string, mixed>> $stepResults
     * @param array<string, mixed>       $envelope
     */
    private function appendToTrace(InputInterface $input, OutputInterface $output, Recipe $recipe, array $envelope, array $stepResults): void
    {
        $summary = sprintf(
            'scaffold %s — %s (%d steps)',
            $recipe->id,
            $envelope['status'],
            count($stepResults),
        );
        $this->traceAppender->appendIfActive($input, $output, TraceEventKind::SCAFFOLD_ACTION, $summary, $envelope);
    }

    /**
     * @param array<string, string> $sharedArgs
     * @param list<array<string, mixed>> $stepResults Accumulator.
     */
    private function runStep(
        string $commandName,
        array $sharedArgs,
        bool $write,
        bool $force,
        bool $override,
        bool $structuredOutput,
        array &$stepResults,
        int $stepNumber,
        Recipe $recipe,
        SymfonyStyle $io,
        ?OutputInterface $ndjsonSink,
    ): int {
        $app = $this->getApplication();
        if ($app === null) {
            $io->error('Application not available — cannot dispatch to sub-commands.');
            return self::FAILURE;
        }

        try {
            $command = $app->find($commandName);
        } catch (CommandNotFoundException) {
            $io->error("Generator '{$commandName}' is not registered.");
            return self::FAILURE;
        }

        $subInput = $this->buildSubInput($commandName, $command->getDefinition()->getOptions(), $sharedArgs, $write, $force, $override, $structuredOutput);
        $buffer = new BufferedOutput();
        $exit = $command->run($subInput, $buffer);
        $rawOutput = trim($buffer->fetch());

        $step = [
            'order'   => $stepNumber,
            'command' => $commandName,
            'exit'    => $exit,
            'raw'     => $rawOutput,
        ];
        $decoded = $this->decodeJsonLine($rawOutput);
        if ($decoded !== null) {
            $step['parsed'] = $decoded;
            unset($step['raw']);
        }
        $stepResults[] = $step;

        if ($ndjsonSink !== null) {
            $ndjsonSink->writeln(json_encode([
                'kind'    => 'step',
                'recipe'  => $recipe->id,
                'order'   => $stepNumber,
                'command' => $commandName,
                'exit'    => $exit,
                'result'  => $decoded ?? $rawOutput,
            ], JSON_UNESCAPED_SLASHES));
        } elseif (!$structuredOutput) {
            $io->section("Step {$stepNumber}/{$commandName}");
            if ($rawOutput !== '') {
                $io->writeln($rawOutput);
            }
        }

        return $exit;
    }

    /**
     * @param list<string> $raw
     * @return array<string, string>
     */
    private function parseArgs(array $raw): array
    {
        $out = [];
        foreach ($raw as $pair) {
            $pair = (string) $pair;
            $eq = strpos($pair, '=');
            if ($eq === false) {
                throw new \InvalidArgumentException("--arg expects key=value, got '{$pair}'");
            }
            $out[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
        }
        return $out;
    }

    /**
     * Forward only the shared args that the target command actually declares —
     * avoids "option does not exist" errors for step-specific args.
     *
     * @param array<string, \Symfony\Component\Console\Input\InputOption> $options
     * @param array<string, string> $sharedArgs
     */
    private function buildSubInput(
        string $commandName,
        array $options,
        array $sharedArgs,
        bool $write,
        bool $force,
        bool $override,
        bool $structuredOutput,
    ): ArrayInput {
        $params = ['command' => $commandName];
        foreach ($sharedArgs as $k => $v) {
            if (isset($options[$k])) {
                $params["--{$k}"] = $v;
            }
        }
        foreach (['write', 'force', 'override-duplicate'] as $flag) {
            if (!isset($options[$flag])) {
                continue;
            }
            $active = match ($flag) {
                'write' => $write,
                'force' => $force,
                default => $override,
            };
            if ($active) {
                $params["--{$flag}"] = true;
            }
        }
        if ($structuredOutput && isset($options['json'])) {
            $params['--json'] = true;
        }
        $input = new ArrayInput($params);
        $input->setInteractive(false);
        return $input;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonLine(string $line): ?array
    {
        if ($line === '' || $line[0] !== '{') {
            return null;
        }
        try {
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($decoded) ? $decoded : null;
    }
}
