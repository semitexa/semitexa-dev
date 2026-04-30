<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Ai\Plan\RiskScorer;
use Semitexa\Dev\Ai\Recipe\RecipeRegistry;
use Semitexa\Dev\Ai\Trace\TraceAutoAppender;
use Semitexa\Dev\Ai\Trace\TraceEventKind;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'ai:plan', description: 'Risk-score a recipe + change set (NDJSON, agent-facing)')]
final class AiPlanCommand extends BaseCommand
{
    #[InjectAsReadonly]
    protected TraceAutoAppender $traceAppender;

    public function __construct()
    {
        parent::__construct('ai:plan');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('recipe', InputArgument::REQUIRED, 'Recipe id (see: ai:task)')
            ->addOption('module', null, InputOption::VALUE_OPTIONAL, 'Module the change targets')
            ->addOption('files', null, InputOption::VALUE_OPTIONAL, 'Comma-separated repo-relative paths the agent intends to touch')
            ->addOption('trace', null, InputOption::VALUE_REQUIRED, 'Append a plan_decision event to this ai:trace id (falls back to $SEMITEXA_AI_TRACE_ID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $recipeId = (string) $input->getArgument('recipe');
        $module = $input->getOption('module');
        $filesRaw = (string) ($input->getOption('files') ?? '');

        $recipe = RecipeRegistry::find($recipeId);
        if ($recipe === null) {
            $output->writeln(json_encode([
                'kind'  => 'error',
                'error' => "unknown recipe: {$recipeId}",
            ], JSON_UNESCAPED_SLASHES));
            return self::FAILURE;
        }

        $files = array_values(array_filter(array_map('trim', explode(',', $filesRaw))));
        $assessment = (new RiskScorer())->score($recipe, $files, $module);

        $output->writeln(json_encode([
            'kind'           => 'summary',
            'recipe'         => $recipe->id,
            'module'         => $module,
            'risk'           => $assessment->level,
            'score'          => $assessment->score,
            'reasons_count'  => count($assessment->reasons),
            'steps_count'    => count($assessment->required_steps),
            'files_in_scope' => count($files),
        ], JSON_UNESCAPED_SLASHES));

        foreach ($assessment->reasons as $reason) {
            $output->writeln(json_encode([
                'kind'   => 'reason',
                'note'   => $reason,
                'action' => 'review',
            ], JSON_UNESCAPED_SLASHES));
        }

        foreach ($assessment->required_steps as $step) {
            $output->writeln(json_encode([
                'kind'   => 'step',
                'note'   => $step,
                'action' => 'run',
            ], JSON_UNESCAPED_SLASHES));
        }

        $summary = sprintf(
            '%s plan for %s — risk=%s score=%d reasons=%d',
            $recipe->id,
            $module ?? 'unscoped',
            $assessment->level,
            $assessment->score,
            count($assessment->reasons),
        );
        $payload = [
            'artifact'       => 'semitexa-dev.ai-plan/v1',
            'recipe'         => $recipe->id,
            'module'         => $module,
            'risk'           => $assessment->level,
            'score'          => $assessment->score,
            'reasons'        => $assessment->reasons,
            'required_steps' => $assessment->required_steps,
            'files_in_scope' => count($files),
        ];
        $this->traceAppender->appendIfActive($input, $output, TraceEventKind::PLAN_DECISION, $summary, $payload);

        return self::SUCCESS;
    }
}
