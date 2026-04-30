<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Ai\Classifier\TaskClassifier;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'ai:task', description: 'Classify a task description into a recipe + suggested make:* invocation')]
final class AiTaskCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TraceAutoAppender $traceAppender;

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
                'next_command'     => $this->buildNextCommands($result),
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
        $this->traceAppender->appendIfActive($input, $output, TraceEventKind::TASK_RESULT, $summary, $payload);
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

    /**
     * Structured next-step suggestions. Same information as `next`, shaped for
     * agents that want to execute without re-parsing prose.
     *
     * @return list<array{cmd: string, args: list<string>, why: string}>
     */
    private function buildNextCommands($result): array
    {
        $id = $result->recipe->id;

        if ($id === 'unknown_task') {
            return [
                ['cmd' => 'ai:epic', 'args' => ['start', '--id=ep-<slug>', '--title="..."', '--goal="..."'], 'why' => 'decompose unfamiliar work into an epic first'],
                ['cmd' => 'ai:ask', 'args' => ['project', '--json'], 'why' => 'structural overview before editing'],
                ['cmd' => 'ai:verify', 'args' => ['--files=<paths>', '--json'], 'why' => 'verify after every edit'],
            ];
        }

        if ($result->recipe->generator_chain === []) {
            return match ($id) {
                'refactor_existing_code' => [
                    ['cmd' => 'ai:review-graph:impact', 'args' => ['<FQCN>', '--json'], 'why' => 'blast radius before editing'],
                    ['cmd' => 'ai:verify', 'args' => ['--files=<paths>', '--json'], 'why' => 'verify after edits'],
                ],
                'debug_investigate' => [
                    ['cmd' => 'logs:app', 'args' => ['--grep=<term>', '--lines=200', '--level=ERROR', '--json'], 'why' => 'recent errors'],
                    ['cmd' => 'ai:ask', 'args' => ['route', '--path=<path>', '--json'], 'why' => 'full endpoint chain'],
                    ['cmd' => 'ai:review-graph:impact', 'args' => ['<FQCN>', '--json'], 'why' => 'dependency graph'],
                ],
                'audit_codebase' => [
                    ['cmd' => 'ai:ask', 'args' => ['project', '--json'], 'why' => 'module inventory'],
                    ['cmd' => 'dev:graph:module', 'args' => ['--name=<Module>', '--json'], 'why' => 'per-module structure'],
                    ['cmd' => 'routes:list', 'args' => ['--json'], 'why' => 'full route surface'],
                    ['cmd' => 'contracts:list', 'args' => ['--json'], 'why' => 'interface → implementation map'],
                ],
                'migrate_or_upgrade' => [
                    ['cmd' => 'ai:epic', 'args' => ['start', '--id=ep-<slug>', '--title="..."', '--goal="..."'], 'why' => 'high-risk work — epic required'],
                    ['cmd' => 'orm:diff', 'args' => ['--json'], 'why' => 'schema delta if relevant'],
                    ['cmd' => 'ai:verify', 'args' => ['--scope=broad', '--json'], 'why' => 'broad verification on close'],
                ],
                'document_or_explain' => [
                    ['cmd' => 'docs:list', 'args' => ['--json'], 'why' => 'existing canonical docs map'],
                ],
                'optimize_performance' => [
                    ['cmd' => 'logs:app', 'args' => ['--grep=<term>', '--lines=200', '--json'], 'why' => 'baseline measurements'],
                    ['cmd' => 'ai:review-graph:impact', 'args' => ['<FQCN>', '--json'], 'why' => 'understand call chain'],
                    ['cmd' => 'ai:verify', 'args' => ['--files=<paths>', '--json'], 'why' => 'catch regressions'],
                ],
                default => [
                    ['cmd' => 'ai:context', 'args' => [$id, '--json'], 'why' => 'prior art for this recipe'],
                ],
            };
        }

        $commands = [];
        foreach ($result->recipe->generator_chain as $i => $step) {
            $args = ['--write', '--json'];
            if ($i === 0 && $result->suggested_module !== null) {
                $args[] = "--module={$result->suggested_module}";
            }
            $commands[] = [
                'cmd'  => $step,
                'args' => $args,
                'why'  => $i === 0 ? 'first step in the recipe chain' : "step " . ($i + 1) . " in the recipe chain",
            ];
        }
        $commands[] = [
            'cmd'  => 'ai:verify',
            'args' => ['--json'],
            'why'  => 'always verify after generators run',
        ];
        return $commands;
    }

    private function buildNextHint($result): string
    {
        $id = $result->recipe->id;

        if ($id === 'unknown_task') {
            return 'no match — decompose via `ai:epic start` + `ai:work start`, explore with `ai:ask project|module|route`, edit with Edit/Write, verify with `ai:verify`. The recipe field is a placeholder and should not drive your next step.';
        }

        if ($result->recipe->generator_chain === []) {
            return match ($id) {
                'refactor_existing_code' => 'refactor path: `ai:review-graph:impact <FQCN>` → read the blast radius, edit with Edit, then `ai:verify`. No generator.',
                'debug_investigate'      => 'debug path: `logs:app --grep=…`, `ai:ask route --path=…` or `ai:ask module --name=…`, `ai:review-graph:impact <FQCN>` before changing. No generator.',
                'audit_codebase'         => 'audit path: `ai:ask project` → `dev:graph:module` per target → `routes:list` / `contracts:list`. Report, do not generate.',
                'migrate_or_upgrade'     => 'migration path: `ai:epic start` is required (risk=high). `orm:diff` for schema moves, targeted `ai:review-graph:impact` before each step, `ai:verify --scope=broad` at the end.',
                'document_or_explain'    => 'docs path: update files under `packages/semitexa-docs/` or the target package `docs/`. Do not create root-level `*.md` unless explicitly asked.',
                'optimize_performance'   => 'perf path: measure first (baseline), change second, re-measure. No generator — use `ai:ask` to locate hot paths and `ai:verify` to avoid regressions.',
                default                  => "no scaffold available for {$id}; edit existing files and run `ai:context {$id}` for prior art.",
            };
        }

        $first = $result->recipe->generator_chain[0];
        $args = ['--write'];
        if ($result->suggested_module !== null) {
            $args[] = "--module={$result->suggested_module}";
        }
        return $first . ' ' . implode(' ', $args) . ' (then run remaining steps in chain).';
    }
}
